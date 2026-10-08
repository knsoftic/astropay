<?php

namespace App\Services\AstroPay;

use App\Enums\AstroPay\GatewayStatus;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Enums\WalletEntryType;
use App\Events\AstroPay\DepositFailed;
use App\Events\AstroPay\DepositSucceeded;
use App\Events\AstroPay\PayoutFailed;
use App\Events\AstroPay\PayoutSucceeded;
use App\Events\AstroPay\TransactionFlaggedForReview;
use App\Exceptions\AstroPay\InvalidTransactionStateException;
use App\Models\AstroPayTransaction;
use App\Models\User;
use App\Services\AstroPay\Support\Amount;
use App\Services\AstroPay\Support\OrderId;
use App\Services\Wallet\WalletService;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place where a transaction changes state.
 *
 * Every method locks the transaction row, applies the transition and the
 * matching wallet effect in the same database transaction, and dispatches
 * events only after commit. Final states are never overwritten: a
 * contradicting report from AstroPay flags the transaction for review.
 */
final class TransactionProcessor
{
    public const REVIEW_ACTION_CREDIT = 'credit';

    public const REVIEW_ACTION_RECLAIM = 'reclaim';

    public const REVIEW_ACTION_DISMISS = 'dismiss';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly AstroPayManager $astropay,
    ) {}

    /**
     * Admin approved a withdrawal: awaiting_approval → initiated.
     * A fresh orderId is used if an earlier attempt was refused by AstroPay.
     */
    public function beginPayoutSubmission(AstroPayTransaction $transaction, ?User $approver): ProcessorResult
    {
        return $this->locked($transaction, function (AstroPayTransaction $tx) use ($approver): UpdateOutcome {
            if (! $tx->isPayout() || $tx->status !== TransactionStatus::AwaitingApproval) {
                throw new InvalidTransactionStateException('This withdrawal is no longer awaiting approval.');
            }

            if ($tx->attempts > 0) {
                // The previous attempt was definitively refused, so its orderId never existed on AstroPay.
                $tx->order_id = OrderId::generate(TransactionType::Payout);
            }

            $tx->attempts++;
            $tx->status = TransactionStatus::Initiated;
            $tx->approved_by = $approver?->getKey();
            $tx->approved_at = now();
            $tx->submitted_at = now();
            $tx->error_code = null;
            $tx->error_message = null;

            return UpdateOutcome::Updated;
        });
    }

    /**
     * The create call succeeded (`code: 1000`).
     */
    public function markSubmitted(AstroPayTransaction $transaction, GatewayResponse $response): ProcessorResult
    {
        return $this->locked($transaction, function (AstroPayTransaction $tx) use ($response): UpdateOutcome {
            $tx->error_code = null;
            $tx->error_message = null;

            if ($tx->isDeposit()) {
                $payUrl = $response->get('pay_url');

                if (self::isHttpUrl($payUrl)) {
                    $tx->pay_url = $payUrl;
                } else {
                    $tx->error_message = 'AstroPay accepted the order but did not return a usable payment link.';
                }
            } else {
                $this->fillDetails($tx, [
                    'commission' => $response->get('commission'),
                    'net_amount' => $response->get('netAmount'),
                    'utr' => $response->get('utr'),
                ]);
            }

            if (! in_array($tx->status, [TransactionStatus::Initiated, TransactionStatus::Unknown], true)) {
                // A callback or status check already moved the order on; keep that state.
                return UpdateOutcome::Unchanged;
            }

            $gatewayStatus = GatewayStatus::fromMixed($response->get('status')) ?? GatewayStatus::Pending;
            $tx->gateway_status = $gatewayStatus->value;
            $tx->status = TransactionStatus::Pending;

            match ($gatewayStatus) {
                GatewayStatus::Success => $this->succeed($tx),
                GatewayStatus::Failed => $this->fail($tx),
                GatewayStatus::Pending => null,
            };

            return UpdateOutcome::Updated;
        });
    }

    /**
     * The create call timed out or AstroPay answered 500/503: the order may
     * or may not exist. Resolved later by a manual status check.
     */
    public function markUnknown(AstroPayTransaction $transaction, ?int $code, string $message): ProcessorResult
    {
        return $this->locked($transaction, function (AstroPayTransaction $tx) use ($code, $message): UpdateOutcome {
            if ($tx->status !== TransactionStatus::Initiated) {
                return UpdateOutcome::Unchanged;
            }

            $tx->status = TransactionStatus::Unknown;
            $tx->error_code = $code;
            $tx->error_message = $message;

            return UpdateOutcome::Updated;
        });
    }

    /**
     * AstroPay definitively refused the create call (400/401/403/404).
     *
     * With admin approval enabled a refused withdrawal goes back to the
     * approval queue (the admin can fix the cause and approve again, or
     * reject it); otherwise it is rejected and the held amount refunded.
     */
    public function markCreateRefused(AstroPayTransaction $transaction, int $code, string $message): ProcessorResult
    {
        return $this->locked($transaction, function (AstroPayTransaction $tx) use ($code, $message): UpdateOutcome {
            if ($tx->status !== TransactionStatus::Initiated) {
                return UpdateOutcome::Unchanged;
            }

            $tx->error_code = $code;
            $tx->error_message = $message;

            if ($tx->isPayout() && $this->astropay->requiresPayoutApproval()) {
                $tx->status = TransactionStatus::AwaitingApproval;
                $tx->approved_by = null;
                $tx->approved_at = null;
                $tx->submitted_at = null;
                $tx->gateway_status = null;

                return UpdateOutcome::Updated;
            }

            $this->reject($tx);

            return UpdateOutcome::Updated;
        });
    }

    /**
     * A status check still returns 404 for an order whose create outcome was
     * unknown, after the grace period: AstroPay never received it.
     */
    public function markNotCreated(AstroPayTransaction $transaction, string $message): ProcessorResult
    {
        return $this->locked($transaction, function (AstroPayTransaction $tx) use ($message): UpdateOutcome {
            if (! in_array($tx->status, [TransactionStatus::Initiated, TransactionStatus::Unknown], true)) {
                return UpdateOutcome::Unchanged;
            }

            $tx->error_code = 404;
            $tx->error_message = $message;
            $this->reject($tx);

            return UpdateOutcome::Updated;
        });
    }

    /**
     * Admin rejected a withdrawal that was awaiting approval; the held amount is refunded.
     */
    public function rejectByAdmin(AstroPayTransaction $transaction, User $admin, string $reason): ProcessorResult
    {
        return $this->locked($transaction, function (AstroPayTransaction $tx) use ($admin, $reason): UpdateOutcome {
            if (! $tx->isPayout() || $tx->status !== TransactionStatus::AwaitingApproval) {
                throw new InvalidTransactionStateException('Only withdrawals awaiting approval can be rejected.');
            }

            $tx->rejected_by = $admin->getKey();
            $tx->rejected_at = now();
            $tx->error_code = null;
            $tx->error_message = 'Rejected by admin: '.$reason;
            $this->reject($tx);

            return UpdateOutcome::Updated;
        });
    }

    /**
     * Apply a status reported by AstroPay (callback or status query).
     *
     * @param  array<string, mixed>  $details  amount, commission, net_amount, utr, remark, is_test, create_time, update_time
     */
    public function applyGatewayStatus(AstroPayTransaction $transaction, GatewayStatus $status, array $details, string $source): ProcessorResult
    {
        return $this->locked($transaction, function (AstroPayTransaction $tx) use ($status, $details, $source): UpdateOutcome {
            $target = TransactionStatus::fromGateway($status);

            if (! $tx->status->isAwaitingGateway()) {
                if ($target === $tx->status) {
                    // Duplicate delivery: keep state, pick up late details such as the UTR.
                    $this->fillDetails($tx, $details);

                    return UpdateOutcome::Unchanged;
                }

                $harmless = ($target === TransactionStatus::Pending && in_array($tx->status, [TransactionStatus::Success, TransactionStatus::Failed], true))
                    || ($target === TransactionStatus::Failed && $tx->status === TransactionStatus::Rejected);

                if ($harmless) {
                    return UpdateOutcome::Unchanged;
                }

                $this->flagForReview($tx, sprintf(
                    'AstroPay reported "%s" (via %s) while the transaction was already "%s". No automatic balance change was made.',
                    $target->label(),
                    $source,
                    $tx->status->label(),
                ));

                return UpdateOutcome::Conflict;
            }

            $this->fillDetails($tx, $details);
            $tx->gateway_status = $status->value;

            if ($target === TransactionStatus::Pending) {
                if ($tx->status === TransactionStatus::Pending) {
                    return UpdateOutcome::Unchanged;
                }

                $tx->status = TransactionStatus::Pending;
                $tx->error_code = null;
                $tx->error_message = null;

                return UpdateOutcome::Updated;
            }

            $target === TransactionStatus::Success ? $this->succeed($tx) : $this->fail($tx);

            return UpdateOutcome::Updated;
        });
    }

    /**
     * Store the latest callback on the transaction without changing its state.
     *
     * @param  array<string, string|null>  $payload
     */
    public function recordCallback(AstroPayTransaction $transaction, array $payload): AstroPayTransaction
    {
        return $this->locked($transaction, function (AstroPayTransaction $tx) use ($payload): UpdateOutcome {
            unset($payload['sign']);
            $tx->last_callback = $payload;
            $tx->callback_received_at = now();

            // Query responses do not carry utr/remark, so keep them from the signed callback.
            $this->fillDetails($tx, [
                'utr' => $payload['utr'] ?? null,
                'remark' => $payload['remark'] ?? null,
            ]);

            return UpdateOutcome::Updated;
        })->transaction;
    }

    /**
     * Resolve a transaction flagged for review.
     *
     *  credit  – deposit: credit the user's wallet (only once) and mark it successful
     *  reclaim – withdrawal that was refunded but later paid by AstroPay: take the refund back
     *  dismiss – no balance change
     */
    public function resolveReview(AstroPayTransaction $transaction, User $admin, string $action, ?string $note): ProcessorResult
    {
        return $this->locked($transaction, function (AstroPayTransaction $tx) use ($admin, $action, $note): UpdateOutcome {
            if (! $tx->needs_review) {
                throw new InvalidTransactionStateException('This transaction is not flagged for review.');
            }

            match ($action) {
                self::REVIEW_ACTION_CREDIT => $this->manualDepositCredit($tx, $admin),
                self::REVIEW_ACTION_RECLAIM => $this->reclaimPayoutRefund($tx, $admin),
                self::REVIEW_ACTION_DISMISS => null,
                default => throw new InvalidArgumentException('Unknown review action.'),
            };

            $tx->needs_review = false;
            $tx->reviewed_by = $admin->getKey();
            $tx->reviewed_at = now();
            $tx->review_note = $note !== null && trim($note) !== '' ? trim($note) : null;

            return UpdateOutcome::Updated;
        });
    }

    private function succeed(AstroPayTransaction $tx): void
    {
        $tx->status = TransactionStatus::Success;
        $tx->completed_at = now();
        $tx->error_code = null;
        $tx->error_message = null;

        $amountMatches = $tx->settled_amount === null || Amount::equals($tx->settled_amount, $tx->amount);

        if ($tx->isDeposit()) {
            if ($amountMatches) {
                $this->creditDeposit($tx);
            } else {
                $this->flagForReview($tx, sprintf(
                    'AstroPay settled %s but %s was requested. The wallet was not credited automatically.',
                    Amount::format($tx->settled_amount, 4),
                    Amount::format($tx->amount, 4),
                ));
            }

            event(new DepositSucceeded($tx));

            return;
        }

        if (! $amountMatches) {
            $this->flagForReview($tx, sprintf(
                'AstroPay reports paying %s but %s was requested.',
                Amount::format($tx->settled_amount, 4),
                Amount::format($tx->amount, 4),
            ));
        }

        event(new PayoutSucceeded($tx));
    }

    private function fail(AstroPayTransaction $tx): void
    {
        $tx->status = TransactionStatus::Failed;
        $tx->completed_at = now();

        if ($tx->isPayout()) {
            // AstroPay returns the funds to the merchant balance; return them to the user too.
            $this->refundPayout($tx, sprintf('Refund: withdrawal %s failed', $tx->order_id));
            event(new PayoutFailed($tx));

            return;
        }

        event(new DepositFailed($tx));
    }

    private function reject(AstroPayTransaction $tx): void
    {
        $tx->status = TransactionStatus::Rejected;
        $tx->completed_at = now();

        if ($tx->isPayout()) {
            $this->refundPayout($tx, sprintf('Refund: withdrawal %s was not processed', $tx->order_id));
            event(new PayoutFailed($tx));

            return;
        }

        event(new DepositFailed($tx));
    }

    private function creditDeposit(AstroPayTransaction $tx, ?int $createdBy = null): void
    {
        if ($tx->user_id === null) {
            return;
        }

        $credit = $this->depositCreditAmount($tx);

        if ($credit === null) {
            $this->flagForReview($tx, 'Net crediting is enabled but the commission is missing or not lower than the amount. The wallet was not credited automatically.');

            return;
        }

        $wallet = $this->wallets->lockedWallet($tx->user_id, $tx->currency);
        $this->wallets->credit($wallet, $credit, WalletEntryType::DepositCredit, $tx, sprintf('Deposit %s', $tx->order_id), $createdBy);
    }

    private function depositCreditAmount(AstroPayTransaction $tx): ?string
    {
        $gross = $tx->settled_amount ?? $tx->amount;

        if (! $this->astropay->creditsNetDeposits()) {
            return Amount::toStorage($gross);
        }

        if ($tx->commission === null) {
            return null;
        }

        $net = Amount::minus($gross, $tx->commission);

        return Amount::isPositive($net) ? $net : null;
    }

    private function manualDepositCredit(AstroPayTransaction $tx, User $admin): void
    {
        if (! $tx->isDeposit() || $tx->user_id === null) {
            throw new InvalidTransactionStateException('Only a user\'s deposit can be credited.');
        }

        if (! in_array($tx->status, [TransactionStatus::Success, TransactionStatus::Failed, TransactionStatus::Rejected], true)) {
            throw new InvalidTransactionStateException('Check the deposit status first; it has not reached a final state.');
        }

        if ($this->wallets->hasEntry($tx, WalletEntryType::DepositCredit)) {
            throw new InvalidTransactionStateException('This deposit has already been credited.');
        }

        $credit = $this->depositCreditAmount($tx);

        if ($credit === null) {
            throw new InvalidTransactionStateException('Cannot work out the amount to credit (commission missing or not lower than the amount).');
        }

        $wallet = $this->wallets->lockedWallet($tx->user_id, $tx->currency);
        $this->wallets->credit($wallet, $credit, WalletEntryType::DepositCredit, $tx, sprintf('Deposit %s (credited after review)', $tx->order_id), $admin->getKey());

        $tx->status = TransactionStatus::Success;
        $tx->gateway_status = GatewayStatus::Success->value;
        $tx->completed_at ??= now();
    }

    private function reclaimPayoutRefund(AstroPayTransaction $tx, User $admin): void
    {
        if (! $tx->isPayout() || $tx->user_id === null) {
            throw new InvalidTransactionStateException('Only a user\'s withdrawal can be reclaimed.');
        }

        if (! $this->wallets->hasEntry($tx, WalletEntryType::PayoutRefund)) {
            throw new InvalidTransactionStateException('No refund was made for this withdrawal, so there is nothing to reclaim.');
        }

        if ($this->wallets->hasEntry($tx, WalletEntryType::PayoutReclaim)) {
            throw new InvalidTransactionStateException('This refund has already been reclaimed.');
        }

        $wallet = $this->wallets->lockedWallet($tx->user_id, $tx->currency);
        // May leave a negative balance: the user was paid and refunded, so they owe the amount.
        $this->wallets->debit($wallet, $tx->amount, WalletEntryType::PayoutReclaim, $tx, sprintf('Refund reversed: withdrawal %s was paid by AstroPay', $tx->order_id), $admin->getKey(), allowNegative: true);

        $tx->status = TransactionStatus::Success;
        $tx->gateway_status = GatewayStatus::Success->value;
        $tx->completed_at ??= now();
    }

    private function refundPayout(AstroPayTransaction $tx, string $description): void
    {
        if ($tx->user_id === null || ! $this->wallets->hasEntry($tx, WalletEntryType::PayoutHold)) {
            return;
        }

        $wallet = $this->wallets->lockedWallet($tx->user_id, $tx->currency);
        $this->wallets->credit($wallet, $tx->amount, WalletEntryType::PayoutRefund, $tx, $description);
    }

    private function flagForReview(AstroPayTransaction $tx, string $reason): void
    {
        $existing = (string) $tx->review_reason;

        if (! str_contains($existing, $reason)) {
            $tx->review_reason = trim($existing."\n".now()->toDateTimeString().' — '.$reason);
        }

        if (! $tx->needs_review) {
            $tx->needs_review = true;
            $tx->reviewed_by = null;
            $tx->reviewed_at = null;
            $tx->review_note = null;
            event(new TransactionFlaggedForReview($tx));
        }

        $this->astropay->logger()->critical('AstroPay transaction flagged for review', [
            'order_id' => $tx->order_id,
            'type' => $tx->type->value,
            'currency' => $tx->currency->value,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function fillDetails(AstroPayTransaction $tx, array $details): void
    {
        if (($amount = Amount::toStorage($details['amount'] ?? null)) !== null) {
            $tx->settled_amount = $amount;
        }

        if (($commission = Amount::toStorage($details['commission'] ?? null)) !== null) {
            $tx->commission = $commission;
        }

        if (($net = Amount::toStorage($details['net_amount'] ?? null)) !== null) {
            $tx->net_amount = $net;
        }

        if (($utr = self::cleanString($details['utr'] ?? null, 64)) !== null) {
            $tx->utr = $utr;
        }

        if (($remark = self::cleanString($details['remark'] ?? null, 1000)) !== null) {
            $tx->remark = $remark;
        }

        if (isset($details['is_test']) && is_scalar($details['is_test'])) {
            $tx->is_test = (int) $details['is_test'] === 1;
        }

        if (($created = self::cleanString($details['create_time'] ?? null, 32)) !== null) {
            $tx->gateway_create_time = $created;
        }

        if (($updated = self::cleanString($details['update_time'] ?? null, 32)) !== null) {
            $tx->gateway_update_time = $updated;
        }
    }

    /**
     * @param  Closure(AstroPayTransaction): UpdateOutcome  $callback
     */
    private function locked(AstroPayTransaction $transaction, Closure $callback): ProcessorResult
    {
        return DB::transaction(function () use ($transaction, $callback): ProcessorResult {
            $tx = AstroPayTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $outcome = $callback($tx);
            $tx->save();

            return new ProcessorResult($tx, $outcome);
        }, 3);
    }

    private static function cleanString(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function isHttpUrl(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
