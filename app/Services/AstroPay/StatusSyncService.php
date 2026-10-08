<?php

namespace App\Services\AstroPay;

use App\Enums\AstroPay\GatewayStatus;
use App\Enums\AstroPay\TransactionStatus;
use App\Exceptions\AstroPay\AstroPayException;
use App\Exceptions\AstroPay\GatewayConnectionException;
use App\Exceptions\AstroPay\GatewayRequestException;
use App\Exceptions\AstroPay\InvalidTransactionStateException;
use App\Models\AstroPayTransaction;

/**
 * Manual reconciliation: POST /v1/payins/query or /v1/payouts/query and
 * apply the result. Webhooks are not retried by AstroPay, so this is how a
 * missed callback or a timed-out create call gets resolved.
 */
final class StatusSyncService
{
    public function __construct(
        private readonly AstroPayManager $astropay,
        private readonly TransactionProcessor $processor,
    ) {}

    /**
     * @throws AstroPayException
     */
    public function sync(AstroPayTransaction $transaction, string $source = 'status check'): ProcessorResult
    {
        if (! $transaction->canCheckStatus()) {
            throw new InvalidTransactionStateException('This transaction has not been sent to AstroPay yet.');
        }

        $client = $this->astropay->client($transaction->currency);

        $transaction->forceFill(['last_checked_at' => now()])->save();

        try {
            $response = $transaction->isDeposit()
                ? $client->queryDeposit($transaction->order_id)
                : $client->queryPayout($transaction->order_id);
        } catch (GatewayRequestException $e) {
            if ($e->isNotFound()) {
                return $this->handleNotFound($transaction);
            }

            throw $e;
        }

        $returnedOrderId = $response->get('orderId');

        if ($returnedOrderId !== null && (string) $returnedOrderId !== $transaction->order_id) {
            throw new GatewayConnectionException(sprintf('AstroPay returned order %s when %s was queried.', (string) $returnedOrderId, $transaction->order_id));
        }

        $status = GatewayStatus::fromMixed($response->get('status'));

        if ($status === null) {
            throw new GatewayConnectionException('AstroPay returned an unrecognised order status: '.json_encode($response->get('status')));
        }

        return $this->processor->applyGatewayStatus($transaction, $status, [
            'amount' => $response->get('amount'),
            'commission' => $response->get('commission'),
            'utr' => $response->get('utr'),
            'is_test' => $response->get('is_test'),
            'create_time' => $response->get('createTime'),
            'update_time' => $response->get('updateTime'),
        ], $source);
    }

    /**
     * 404 from the query endpoint. Only an order whose create outcome was
     * unknown, and that is older than the grace period, is treated as never
     * created; anything else is surfaced as an error.
     */
    private function handleNotFound(AstroPayTransaction $transaction): ProcessorResult
    {
        if ($transaction->status === TransactionStatus::Rejected) {
            // Consistent: a rejected order was never created on AstroPay.
            return new ProcessorResult($transaction->refresh(), UpdateOutcome::Unchanged);
        }

        if (! in_array($transaction->status, [TransactionStatus::Initiated, TransactionStatus::Unknown], true)) {
            throw new InvalidTransactionStateException(sprintf(
                'AstroPay has no record of order %s although it is "%s" locally. Contact AstroPay support.',
                $transaction->order_id,
                $transaction->status->label(),
            ));
        }

        $grace = $this->astropay->notFoundGraceMinutes();
        $sentAt = $transaction->submitted_at ?? $transaction->created_at;

        if ($sentAt->gt(now()->subMinutes($grace))) {
            throw new InvalidTransactionStateException(sprintf(
                'AstroPay has no record of this order yet. Check again after %s.',
                $sentAt->copy()->addMinutes($grace)->format('H:i'),
            ));
        }

        return $this->processor->markNotCreated($transaction, sprintf('AstroPay has no record of this order %d minutes after it was sent.', $grace));
    }
}
