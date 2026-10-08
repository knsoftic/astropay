<?php

namespace App\Enums\AstroPay;

/**
 * Payment method codes from Reference → Payment Method Codes.
 */
enum PaymentMethod: string
{
    case UPI = 'UPI';
    case EASYPAISA = 'EASYPAISA';
    case JAZZCASH = 'JAZZCASH';
    case BKASH = 'BKASH';
    case NAGAD = 'NAGAD';

    public function currency(): Currency
    {
        return match ($this) {
            self::UPI => Currency::INR,
            self::EASYPAISA, self::JAZZCASH => Currency::PKR,
            self::BKASH, self::NAGAD => Currency::BDT,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UPI => 'UPI / Bank (IMPS)',
            self::EASYPAISA => 'Easypaisa',
            self::JAZZCASH => 'JazzCash',
            self::BKASH => 'bKash',
            self::NAGAD => 'Nagad',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $method) => $method->value, self::cases());
    }
}
