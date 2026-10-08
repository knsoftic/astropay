<?php

namespace App\Enums\AstroPay;

enum TransactionType: string
{
    case Deposit = 'deposit';
    case Payout = 'payout';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Payout => 'Withdrawal',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
