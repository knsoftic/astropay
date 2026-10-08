<?php

namespace App\Services\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\ConfigurationException;
use App\Exceptions\AstroPay\GatewayConnectionException;
use App\Exceptions\AstroPay\GatewayRequestException;
use App\Models\AstroPayTransaction;
use App\Models\User;
use App\Services\AstroPay\Support\Amount;
use App\Services\AstroPay\Support\CustomerDetails;
use App\Services\AstroPay\Support\OrderId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Collection (deposit) orders: POST /v1/payins/create.
 */
final class DepositService
{
    public function __construct(
        private readonly AstroPayManager $astropay,
        private readonly TransactionProcessor $processor,
    ) {}

    /**
     * Create a deposit order and return the transaction.
     *
     * Resulting status:
     *  - pending  → redirect the customer to $transaction->pay_url
     *  - rejected → AstroPay refused it (see error_message)
     *  - unknown  → no definitive answer; resolve with a status check
     *
     * @param  array{name?: mixed, phone?: mixed, email?: mixed}  $customer
     *
     * @throws ValidationException
     * @throws ConfigurationException
     */
    public function create(?User $user, Currency $currency, mixed $amount, array $customer, ?PaymentMethod $method = null, ?string $idempotencyKey = null): AstroPayTransaction
    {
        if (! $this->astropay->isEnabled($currency)) {
            throw ValidationException::withMessages(['currency' => sprintf('%s deposits are not available.', $currency->value)]);
        }

        if ($method !== null && ($method->currency() !== $currency || ! $currency->usesPaymentMethods())) {
            throw ValidationException::withMessages(['payment_method' => sprintf('%s is not available for %s.', $method->label(), $currency->value)]);
        }

        $limits = $this->astropay->limits($currency, TransactionType::Deposit);
        $amount = Amount::validate($amount, $limits['min'], $limits['max']);
        $customer = CustomerDetails::normalize($currency, $customer);
        $callbackUrl = $this->astropay->callbackUrl(TransactionType::Deposit, $currency);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey, $user)) !== null) {
            return $existing;
        }

        try {
            $transaction = AstroPayTransaction::query()->create([
                'user_id' => $user?->getKey(),
                'type' => TransactionType::Deposit,
                'currency' => $currency,
                'payment_method' => $method,
                'order_id' => OrderId::generate(TransactionType::Deposit),
                'idempotency_key' => $idempotencyKey,
                'attempts' => 1,
                'amount' => $amount,
                'status' => TransactionStatus::Initiated,
                'customer_name' => $customer['name'],
                'customer_phone' => $customer['phone'],
                'customer_email' => $customer['email'],
                'submitted_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A parallel request with the same idempotency key won the race.
            if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey, $user)) !== null) {
                return $existing;
            }

            throw $e;
        }

        $payload = [
            'orderId' => $transaction->order_id,
            'amount' => $amount,
            'callbackUrl' => $callbackUrl,
            'name' => $customer['name'],
            'phone' => $customer['phone'],
            'email' => $customer['email'],
        ];

        if ($method !== null) {
            // Omitted otherwise so AstroPay picks the method automatically (always omitted for USDT).
            $payload['channel'] = $method->value;
        }

        try {
            $response = $this->astropay->client($currency)->createDeposit($payload);
        } catch (GatewayRequestException $e) {
            return $e->isOutcomeUnknown()
                ? $this->processor->markUnknown($transaction, $e->gatewayCode, $e->getMessage())->transaction
                : $this->processor->markCreateRefused($transaction, $e->gatewayCode, $e->gatewayMessage !== '' ? $e->gatewayMessage : $e->getMessage())->transaction;
        } catch (GatewayConnectionException $e) {
            return $this->processor->markUnknown($transaction, $e->httpStatus, $e->getMessage())->transaction;
        }

        $returnedOrderId = $response->get('order_id');

        if ($returnedOrderId !== null && (string) $returnedOrderId !== $transaction->order_id) {
            $this->astropay->logger()->warning('AstroPay returned a different order_id for a deposit', [
                'order_id' => $transaction->order_id,
                'returned' => $returnedOrderId,
            ]);
        }

        return $this->processor->markSubmitted($transaction, $response)->transaction;
    }

    /**
     * @throws ValidationException when the key belongs to another user or operation
     */
    private function findByIdempotencyKey(string $key, ?User $user): ?AstroPayTransaction
    {
        $existing = AstroPayTransaction::query()->where('idempotency_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->type !== TransactionType::Deposit || $existing->user_id !== $user?->getKey()) {
            throw ValidationException::withMessages(['idempotency_key' => 'This form was already submitted. Please reload the page.']);
        }

        return $existing;
    }
}
