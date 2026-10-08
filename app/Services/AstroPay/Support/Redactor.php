<?php

namespace App\Services\AstroPay\Support;

/**
 * Removes credentials and masks personal data before anything is logged.
 */
final class Redactor
{
    private const SECRET_KEYS = ['merchantkey', 'secretkey', 'sign'];

    private const MASKED_KEYS = ['account', 'accountphone', 'phone', 'email', 'pay_url'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);

            if (in_array($normalized, self::SECRET_KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (in_array($normalized, self::MASKED_KEYS, true) && is_scalar($value)) {
                $data[$key] = self::mask((string) $value);
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    public static function mask(string $value): string
    {
        $length = mb_strlen($value);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 2).str_repeat('*', $length - 4).mb_substr($value, -2);
    }
}
