<?php

namespace App\Services\AstroPay\Support;

/**
 * TRON (TRC20) address validation: Base58Check, version byte 0x41, 34 chars
 * starting with "T". The checksum catches typos before funds are sent.
 */
final class TronAddress
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function isValid(string $address): bool
    {
        if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address) !== 1) {
            return false;
        }

        $bytes = self::decode($address);

        if ($bytes === null || strlen($bytes) !== 25 || ord($bytes[0]) !== 0x41) {
            return false;
        }

        $payload = substr($bytes, 0, 21);
        $checksum = substr($bytes, 21, 4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return hash_equals($expected, $checksum);
    }

    private static function decode(string $input): ?string
    {
        // Little-endian base-256 digits.
        $bytes = [];

        foreach (str_split($input) as $char) {
            $carry = strpos(self::ALPHABET, $char);

            if ($carry === false) {
                return null;
            }

            foreach ($bytes as $index => $byte) {
                $carry += $byte * 58;
                $bytes[$index] = $carry & 0xFF;
                $carry >>= 8;
            }

            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }

        $leadingZeros = strspn($input, '1');

        return str_repeat("\x00", $leadingZeros).implode('', array_map('chr', array_reverse($bytes)));
    }
}
