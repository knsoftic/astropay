<?php

namespace App\Enums\AstroPay;

/**
 * Order status as reported by AstroPay (query responses and callbacks).
 * Query responses send it as a number, callbacks as a string.
 */
enum GatewayStatus: int
{
    case Pending = 1;
    case Failed = 9;
    case Success = 10;

    public static function fromMixed(mixed $value): ?self
    {
        if (is_int($value)) {
            return self::tryFrom($value);
        }

        if (is_string($value) && preg_match('/^\d{1,3}$/', trim($value)) === 1) {
            return self::tryFrom((int) trim($value));
        }

        return null;
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
