<?php

namespace App\Services\AstroPay\Support;

use App\Enums\AstroPay\Currency;

/**
 * Normalises mobile numbers to the local format each market's wallets use.
 */
final class PhoneNumber
{
    /**
     * India: 10 digits starting 6-9 ("9876543210").
     */
    public static function india(string $input): ?string
    {
        $digits = self::digits($input);

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Pakistan: "03XXXXXXXXX" (Easypaisa/JazzCash wallet numbers).
     */
    public static function pakistan(string $input): ?string
    {
        $digits = self::digits($input);

        if (str_starts_with($digits, '0092')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '92') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        } elseif (str_starts_with($digits, '3') && strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return preg_match('/^03\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Bangladesh: "01XXXXXXXXX" (bKash/Nagad wallet numbers).
     */
    public static function bangladesh(string $input): ?string
    {
        $digits = self::digits($input);

        if (str_starts_with($digits, '00880')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '880') && strlen($digits) === 13) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '1') && strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Any international number: 7-15 digits.
     */
    public static function generic(string $input): ?string
    {
        $digits = self::digits($input);

        return preg_match('/^\d{7,15}$/', $digits) === 1 ? $digits : null;
    }

    public static function forCurrency(Currency $currency, string $input): ?string
    {
        return match ($currency) {
            Currency::INR => self::india($input),
            Currency::PKR => self::pakistan($input),
            Currency::BDT => self::bangladesh($input),
            Currency::USDT => self::generic($input),
        };
    }

    public static function exampleFor(Currency $currency): string
    {
        return match ($currency) {
            Currency::INR => '9876543210',
            Currency::PKR => '03001234567',
            Currency::BDT => '01712345678',
            Currency::USDT => '15551234567',
        };
    }

    private static function digits(string $input): string
    {
        $input = trim($input);

        // Only spaces, dashes, dots, brackets and a leading "+" are tolerated.
        if (preg_match('/^\+?[\d\s\-().]+$/', $input) !== 1) {
            return '';
        }

        return preg_replace('/\D/', '', $input) ?? '';
    }
}
