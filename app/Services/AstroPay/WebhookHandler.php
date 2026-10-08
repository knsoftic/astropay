<?php

namespace App\Services\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\GatewayStatus;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\AstroPayException;
use App\Models\AstroPayTransaction;
use App\Models\AstroPayWebhookLog;
use App\Services\AstroPay\Support\CallbackPayload;
use App\Services\AstroPay\Support\Signature;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Handles AstroPay callback notifications (Webhooks → Callback Notifications):
 *
 *  1. optional source-IP allowlist
 *  2. parse the body (JSON or form) keeping values verbatim
 *  3. verify `sign` with the account's secretKey — reject with 4xx if invalid
 *  4. find the order, check it belongs to this type/currency
 *  5. optionally confirm the final status with a status query
 *  6. apply it idempotently (duplicates are a no-op) and answer 2xx
 *
 * Every delivery is recorded in astropay_webhook_logs.
 */
final class WebhookHandler
{
    public function __construct(
        private readonly AstroPayManager $astropay,
        private readonly TransactionProcessor $processor,
        private readonly StatusSyncService $statusSync,
    ) {}

    public function handle(Request $request, TransactionType $type, Currency $currency): WebhookResult
    {
        $log = new AstroPayWebhookLog([
            'type' => $type->value,
            'currency' => $currency->value,
            'ip' => $request->ip(),
            'content_type' => mb_substr((string) $request->header('Content-Type', ''), 0, 191),
        ]);

        try {
            $result = $this->process($request, $type, $currency, $log);
        } catch (Throwable $e) {
            report($e);
            $result = new WebhookResult(500, 'error', 'Internal error while processing the callback.');
        }

        $this->writeLog($log, $result);

        return $result;
    }

    private function process(Request $request, TransactionType $type, Currency $currency, AstroPayWebhookLog $log): WebhookResult
    {
        $allowedIps = $this->astropay->allowedWebhookIps();

        if ($allowedIps !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowedIps)) {
            return new WebhookResult(403, 'forbidden_ip', 'Source IP is not allowed.');
        }

        // Credentials, not the "enabled" flag: callbacks for orders created
        // before a currency was disabled must still be verified and applied.
        if (! $this->astropay->hasCredentials($currency)) {
            return new WebhookResult(404, 'currency_not_configured', sprintf('%s is not configured.', $currency->value));
        }

        try {
            $payload = CallbackPayload::fromRequest($request);
        } catch (InvalidArgumentException $e) {
            return new WebhookResult(400, 'malformed', $e->getMessage());
        }

        $log->payload = $payload;
        $orderId = $payload['orderId'] ?? null;
        $log->order_id = is_string($orderId) ? mb_substr($orderId, 0, 64) : null;

        foreach (['orderId', 'status', 'sign'] as $field) {
            if (! isset($payload[$field]) || $payload[$field] === '') {
                return new WebhookResult(400, 'missing_fields', sprintf('Missing field: %s.', $field));
            }
        }

        if (! Signature::verifyCallback($payload, $this->astropay->secretKey($currency))) {
            $this->astropay->logger()->warning('AstroPay callback with invalid signature', [
                'type' => $type->value,
                'currency' => $currency->value,
                'order_id' => $log->order_id,
                'ip' => $request->ip(),
            ]);

            return new WebhookResult(401, 'invalid_signature', 'Signature verification failed.');
        }

        $log->signature_valid = true;

        $transaction = AstroPayTransaction::query()->where('order_id', (string) $orderId)->first();

        if ($transaction === null) {
            return new WebhookResult(404, 'unknown_order', 'Order not found.');
        }

        $log->transaction_id = $transaction->getKey();

        if ($transaction->type !== $type || $transaction->currency !== $currency) {
            $this->astropay->logger()->critical('AstroPay callback type/currency mismatch', [
                'order_id' => $transaction->order_id,
                'expected' => [$transaction->type->value, $transaction->currency->value],
                'received' => [$type->value, $currency->value],
            ]);

            return new WebhookResult(400, 'mismatch', 'Order does not belong to this callback URL.', $transaction);
        }

        $status = GatewayStatus::fromMixed($payload['status']);

        if ($status === null) {
            return new WebhookResult(400, 'invalid_status', 'Unrecognised status value.', $transaction);
        }

        $transaction = $this->processor->recordCallback($transaction, $payload);

        if ($status->isFinal() && $this->astropay->confirmsCallbacksViaQuery()) {
            return $this->confirmAndApply($transaction, $status);
        }

        $result = $this->processor->applyGatewayStatus($transaction, $status, [
            'amount' => $payload['amount'] ?? null,
            'commission' => $payload['commission'] ?? null,
            'utr' => $payload['utr'] ?? null,
            'remark' => $payload['remark'] ?? null,
        ], 'callback');

        return $this->resultFor($result, $status);
    }

    /**
     * Re-query AstroPay and apply what the query says, so a forged callback
     * (e.g. after a leaked secretKey) cannot settle an order on its own.
     */
    private function confirmAndApply(AstroPayTransaction $transaction, GatewayStatus $callbackStatus): WebhookResult
    {
        try {
            $result = $this->statusSync->sync($transaction, 'callback (confirmed by status query)');
        } catch (AstroPayException $e) {
            $this->astropay->logger()->warning('AstroPay callback could not be confirmed', [
                'order_id' => $transaction->order_id,
                'error' => $e->getMessage(),
            ]);

            // Recorded on the transaction; a manual status check will settle it.
            return new WebhookResult(200, 'unconfirmed', 'Callback recorded; confirmation query failed: '.$e->getMessage(), $transaction);
        }

        if ($result->outcome !== UpdateOutcome::Conflict && $result->transaction->status !== TransactionStatus::fromGateway($callbackStatus)) {
            $this->astropay->logger()->warning('AstroPay status query does not match the callback', [
                'order_id' => $transaction->order_id,
                'callback_status' => $callbackStatus->value,
                'local_status' => $result->transaction->status->value,
            ]);

            return new WebhookResult(200, 'unconfirmed', sprintf(
                'Callback reported status %d but the status query left the order "%s".',
                $callbackStatus->value,
                $result->transaction->status->value,
            ), $result->transaction);
        }

        return $this->resultFor($result, $callbackStatus);
    }

    private function resultFor(ProcessorResult $result, GatewayStatus $status): WebhookResult
    {
        return match ($result->outcome) {
            UpdateOutcome::Updated => new WebhookResult(200, 'processed', sprintf('Order is now "%s".', $result->transaction->status->value), $result->transaction),
            UpdateOutcome::Unchanged => new WebhookResult(200, 'duplicate', sprintf('Status %d already applied.', $status->value), $result->transaction),
            UpdateOutcome::Conflict => new WebhookResult(200, 'conflict', 'Conflicting status; flagged for manual review.', $result->transaction),
        };
    }

    private function writeLog(AstroPayWebhookLog $log, WebhookResult $result): void
    {
        try {
            $log->fill([
                'outcome' => $result->outcome,
                'http_status' => $result->httpStatus,
                'message' => mb_substr($result->message, 0, 2000),
            ])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
