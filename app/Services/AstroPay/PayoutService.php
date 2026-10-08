<?php

namespace App\Services\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Enums\WalletEntryType;
use App\Exceptions\AstroPay\ConfigurationException;
use App\Exceptions\AstroPay\GatewayConnectionException;
use App\Exceptions\AstroPay\GatewayRequestException;
use App\Exceptions\AstroPay\InvalidTransactionStateException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\AstroPayTransaction;
use App\Models\User;
use App\Services\AstroPay\Support\Amount;
use App\Services\AstroPay\Support\OrderId;
use App\Services\AstroPay\Support\PayoutDetails;
use App\Services\Wallet\WalletService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment (withdrawal) orders: POST /v1/payouts/create.
 *
 * Flow: request() holds the amount in the user's wallet → (admin approves)
 * → submit() sends the order → callback / status check settles it. A failed
 * or rejected withdrawal refunds the held amount.
 */
final class PayoutService
{
    public function __construct(
        private readonly AstroPayManager $astropay,
        private readonly TransactionProcessor $processor,
        private readonly WalletService $wallets,
    ) {}

    /**
     * Create a withdrawal request. With approval enabled it waits for an
     * admin; otherwise it is sent to AstroPay immediately.
     *
     * Pass a null user for a merchant-initiated payout that is not paid
     * from a user wallet.
     *
     * @param  array{account?: mixed, bank_code?: mixed, account_phone?: mixed, person_name?: mixed}  $beneficiary
     *
     * @throws ValidationException
     * @throws ConfigurationException
     */
    public function request(?User $user, Currency $currency, mixed $amount, ?PaymentMethod $method, array $beneficiary, ?string $idempotencyKey = null): AstroPayTransaction
    {
        if (! $this->astropay->isEnabled($currency)) {
            throw ValidationException::withMessages(['currency' => sprintf('%s withdrawals are not available.', $currency->value)]);
        }

        $limits = $this->astropay->limits($currency, TransactionType::Payout);
        $amount = Amount::validate($amount, $limits['min'], $limits['max']);
        $details = PayoutDetails::normalize($currency, $method, $beneficiary);

        // Fail early on a bad callback URL rather than after holding the funds.
        $this->astropay->callbackUrl(TransactionType::Payout, $currency);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey, $user)) !== null) {
            return $existing;
        }

        $requiresApproval = $this->astropay->requiresPayoutApproval();

        try {
            $transaction = DB::transaction(function () use ($user, $currency, $method, $amount, $details, $idempotencyKey, $requiresApproval): AstroPayTransaction {
                $transaction = AstroPayTransaction::query()->create([
                    'user_id' => $user?->getKey(),
                    'type' => TransactionType::Payout,
                    'currency' => $currency,
                    'payment_method' => $currency->usesPaymentMethods() ? $method : null,
                    'order_id' => OrderId::generate(TransactionType::Payout),
                    'idempotency_key' => $idempotencyKey,
                    'attempts' => $requiresApproval ? 0 : 1,
                    'amount' => $amount,
                    'status' => $requiresApproval ? TransactionStatus::AwaitingApproval : TransactionStatus::Initiated,
                    'beneficiary_account' => $details['account'],
                    'beneficiary_bank_code' => $details['bank_code'] !== '' ? $details['bank_code'] : null,
                    'beneficiary_phone' => $details['account_phone'] !== '' ? $details['account_phone'] : null,
                    'beneficiary_name' => $details['person_name'] !== '' ? $details['person_name'] : null,
                    'submitted_at' => $requiresApproval ? null : now(),
                ]);

                if ($user !== null) {
                    $wallet = $this->wallets->lockedWallet($user->getKey(), $currency);

                    try {
                        $this->wallets->debit($wallet, $amount, WalletEntryType::PayoutHold, $transaction, sprintf('Withdrawal %s', $transaction->order_id));
                    } catch (InsufficientWalletBalanceException $e) {
                        throw ValidationException::withMessages([
                            'amount' => sprintf('Insufficient balance. Available: %s %s.', Amount::format($e->available), $currency->value),
                        ]);
                    }
                }

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey, $user)) !== null) {
                return $existing;
            }

            throw $e;
        }

        return $requiresApproval ? $transaction : $this->submit($transaction);
    }

    /**
     * Admin approval: send the withdrawal to AstroPay.
     *
     * @throws InvalidTransactionStateException
     */
    public function approve(AstroPayTransaction $transaction, User $admin): AstroPayTransaction
    {
        $transaction = $this->processor->beginPayoutSubmission($transaction, $admin)->transaction;

        return $this->submit($transaction);
    }

    /**
     * Admin rejection of a withdrawal awaiting approval (held amount is refunded).
     *
     * @throws InvalidTransactionStateException
     */
    public function reject(AstroPayTransaction $transaction, User $admin, string $reason): AstroPayTransaction
    {
        return $this->processor->rejectByAdmin($transaction, $admin, $reason)->transaction;
    }

    /**
     * Send an initiated payout to AstroPay. Never retried automatically: a
     * timeout leaves it "unknown" until a status check resolves it, because a
     * blind retry could pay the beneficiary twice.
     */
    private function submit(AstroPayTransaction $transaction): AstroPayTransaction
    {
        if ($transaction->status !== TransactionStatus::Initiated) {
            throw new InvalidTransactionStateException('Only initiated withdrawals can be sent to AstroPay.');
        }

        try {
            $payload = $this->payload($transaction);
            $response = $this->astropay->client($transaction->currency)->createPayout($payload);
        } catch (ConfigurationException $e) {
            // Nothing was sent; treat it like a definitive refusal.
            return $this->processor->markCreateRefused($transaction, 0, $e->getMessage())->transaction;
        } catch (GatewayRequestException $e) {
            return $e->isOutcomeUnknown()
                ? $this->processor->markUnknown($transaction, $e->gatewayCode, $e->getMessage())->transaction
                : $this->processor->markCreateRefused($transaction, $e->gatewayCode, $e->gatewayMessage !== '' ? $e->gatewayMessage : $e->getMessage())->transaction;
        } catch (GatewayConnectionException $e) {
            return $this->processor->markUnknown($transaction, $e->httpStatus, $e->getMessage())->transaction;
        }

        return $this->processor->markSubmitted($transaction, $response)->transaction;
    }

    /**
     * Request body per Reference → Payment Method Codes.
     *
     * @return array<string, string>
     */
    private function payload(AstroPayTransaction $transaction): array
    {
        $payload = [
            'orderId' => $transaction->order_id,
            'amount' => Amount::toRequest($transaction->amount),
            'callbackUrl' => $this->astropay->callbackUrl(TransactionType::Payout, $transaction->currency),
        ];

        if ($transaction->currency === Currency::USDT) {
            // accountType is omitted entirely; bank_code and accountPhone are not used.
            $payload['account'] = (string) $transaction->beneficiary_account;

            if (filled($transaction->beneficiary_name)) {
                $payload['personName'] = (string) $transaction->beneficiary_name;
            }

            return $payload;
        }

        return $payload + [
            'accountType' => (string) $transaction->payment_method?->value,
            'account' => (string) $transaction->beneficiary_account,
            'bank_code' => (string) ($transaction->beneficiary_bank_code ?? ''),
            'accountPhone' => (string) ($transaction->beneficiary_phone ?? ''),
            'personName' => (string) $transaction->beneficiary_name,
        ];
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

        if ($existing->type !== TransactionType::Payout || $existing->user_id !== $user?->getKey()) {
            throw ValidationException::withMessages(['idempotency_key' => 'This form was already submitted. Please reload the page.']);
        }

        return $existing;
    }
}
