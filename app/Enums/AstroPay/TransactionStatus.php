<?php

namespace App\Enums\AstroPay;

/**
 * Local lifecycle of a deposit or payout.
 *
 *  awaiting_approval → initiated → pending → success | failed
 *                          ↘ unknown (create outcome not known) → pending | success | failed | rejected
 *                          ↘ rejected (AstroPay refused / never received the order)
 */
enum TransactionStatus: string
{
    // Payout requested by a user, wallet amount held, waiting for an admin.
    case AwaitingApproval = 'awaiting_approval';

    // Saved locally, create call in progress.
    case Initiated = 'initiated';

    // Accepted by AstroPay (gateway status 1).
    case Pending = 'pending';

    // The create call timed out or AstroPay answered 500/503, so we cannot
    // tell whether the order exists. Resolved by a manual status check.
    case Unknown = 'unknown';

    case Success = 'success';

    case Failed = 'failed';

    // Never created on AstroPay's side (refused, not found, or rejected by an admin).
    case Rejected = 'rejected';

    public static function fromGateway(GatewayStatus $status): self
    {
        return match ($status) {
            GatewayStatus::Pending => self::Pending,
            GatewayStatus::Failed => self::Failed,
            GatewayStatus::Success => self::Success,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Rejected], true);
    }

    /**
     * States in which the order may exist on AstroPay but has not settled.
     */
    public function isAwaitingGateway(): bool
    {
        return in_array($this, [self::Initiated, self::Pending, self::Unknown], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingApproval => 'Awaiting approval',
            self::Initiated => 'Initiated',
            self::Pending => 'Pending',
            self::Unknown => 'Processing',
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Failed, self::Rejected => 'danger',
            self::AwaitingApproval, self::Unknown => 'warning',
            self::Initiated, self::Pending => 'info',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
