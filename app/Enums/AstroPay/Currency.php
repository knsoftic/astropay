<?php

namespace App\Enums\AstroPay;

/**
 * Settlement currencies. Each AstroPay merchant account is provisioned with
 * exactly one of these, so the currency selects which credentials to use.
 */
enum Currency: string
{
    case INR = 'INR';
    case PKR = 'PKR';
    case BDT = 'BDT';
    case USDT = 'USDT';

    public function label(): string
    {
        return match ($this) {
            self::INR => 'Indian Rupee (INR)',
            self::PKR => 'Pakistani Rupee (PKR)',
            self::BDT => 'Bangladeshi Taka (BDT)',
            self::USDT => 'Tether (USDT, TRC20)',
        };
    }

    /**
     * Valid `channel` (deposit) / `accountType` (payout) values for this currency.
     *
     * @return list<PaymentMethod>
     */
    public function paymentMethods(): array
    {
        return array_values(array_filter(
            PaymentMethod::cases(),
            fn (PaymentMethod $method) => $method->currency() === $this,
        ));
    }

    /**
     * USDT has no payment method: `channel` is omitted on deposits and
     * `accountType` is omitted entirely on payouts.
     */
    public function usesPaymentMethods(): bool
    {
        return $this !== self::USDT;
    }

    /**
     * UTR query/supplement only applies to UPI (INR) accounts.
     */
    public function supportsUtrTools(): bool
    {
        return $this === self::INR;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $currency) => $currency->value, self::cases());
    }
}
