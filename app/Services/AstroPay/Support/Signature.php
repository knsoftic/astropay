<?php

namespace App\Services\AstroPay\Support;

/**
 * Callback signature from Reference → Signature Algorithm:
 *
 *  1. take orderId, amount, commission, status, utr
 *  2. sort keys alphabetically
 *  3. concatenate "key=value&" for every non-null, non-empty value
 *  4. append "secret=<secretKey>"
 *  5. sign = strtoupper(md5(string))
 */
final class Signature
{
    /**
     * Fields covered by the callback signature (never `remark` or `sign`).
     */
    public const CALLBACK_FIELDS = ['orderId', 'amount', 'commission', 'status', 'utr'];

    /**
     * @param  array<string, mixed>  $params
     */
    public static function generate(array $params, string $secret): string
    {
        return strtoupper(md5(self::signString($params, $secret)));
    }

    /**
     * The exact string that is hashed (exposed for debugging and tests).
     *
     * @param  array<string, mixed>  $params
     */
    public static function signString(array $params, string $secret): string
    {
        ksort($params, SORT_STRING);

        $signString = '';

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $signString .= $key.'='.self::stringify($value).'&';
        }

        return $signString.'secret='.$secret;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forCallback(array $payload, string $secret): string
    {
        return self::generate(array_intersect_key($payload, array_flip(self::CALLBACK_FIELDS)), $secret);
    }

    /**
     * Constant-time, case-sensitive comparison as required by the docs.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function verifyCallback(array $payload, string $secret): bool
    {
        $sign = $payload['sign'] ?? null;

        if (! is_string($sign) || $sign === '' || $secret === '') {
            return false;
        }

        return hash_equals(self::forCallback($payload, $secret), $sign);
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
