<?php

namespace App\Services\AstroPay\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Exact decimal handling for money. Floats are never used for arithmetic.
 */
final class Amount
{
    /** Scale used in the database (AstroPay reports amounts like "500.0000"). */
    public const STORAGE_SCALE = 4;

    /** Scale sent to AstroPay (documented examples use "500.00"). */
    public const REQUEST_SCALE = 2;

    /** User input: positive number, up to 12 integer digits and 2 decimals. */
    public const INPUT_PATTERN = '/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/';

    public static function of(mixed $value): ?BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (is_int($value)) {
            return BigDecimal::of($value);
        }

        if (is_float($value)) {
            return is_finite($value)
                ? BigDecimal::of(number_format($value, self::STORAGE_SCALE, '.', ''))
                : null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
                return null;
            }

            try {
                return BigDecimal::of($value);
            } catch (MathException) {
                return null;
            }
        }

        return null;
    }

    /**
     * Normalise any gateway/user amount to the storage scale, or null if it is not a number.
     */
    public static function toStorage(mixed $value): ?string
    {
        $decimal = self::of($value);

        return $decimal?->toScale(self::STORAGE_SCALE, RoundingMode::HALF_UP)->__toString();
    }

    /**
     * Format a validated amount for an AstroPay request body.
     */
    public static function toRequest(mixed $value): string
    {
        $decimal = self::of($value) ?? throw new \InvalidArgumentException('Invalid amount.');

        return $decimal->toScale(self::REQUEST_SCALE, RoundingMode::HALF_UP)->__toString();
    }

    public static function equals(mixed $a, mixed $b): bool
    {
        $left = self::of($a);
        $right = self::of($b);

        return $left !== null && $right !== null && $left->isEqualTo($right);
    }

    public static function plus(mixed $a, mixed $b): string
    {
        return self::require($a)->plus(self::require($b))->toScale(self::STORAGE_SCALE, RoundingMode::HALF_UP)->__toString();
    }

    public static function minus(mixed $a, mixed $b): string
    {
        return self::require($a)->minus(self::require($b))->toScale(self::STORAGE_SCALE, RoundingMode::HALF_UP)->__toString();
    }

    public static function negate(mixed $a): string
    {
        return self::require($a)->negated()->toScale(self::STORAGE_SCALE, RoundingMode::HALF_UP)->__toString();
    }

    public static function compare(mixed $a, mixed $b): int
    {
        return self::require($a)->compareTo(self::require($b));
    }

    public static function isPositive(mixed $a): bool
    {
        return self::of($a)?->isPositive() ?? false;
    }

    /**
     * Validate a user-entered amount against optional limits and return it
     * formatted for the API ("1500.00").
     *
     * @throws ValidationException
     */
    public static function validate(mixed $input, mixed $min = null, mixed $max = null, string $field = 'amount'): string
    {
        $input = is_string($input) ? trim($input) : (is_int($input) ? (string) $input : '');

        if (preg_match(self::INPUT_PATTERN, $input) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a valid amount with at most 2 decimal places.']);
        }

        $amount = BigDecimal::of($input);

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages([$field => 'The amount must be greater than zero.']);
        }

        $minimum = ($min === null || $min === '') ? null : self::of($min);
        $maximum = ($max === null || $max === '') ? null : self::of($max);

        if ($minimum !== null && $amount->isLessThan($minimum)) {
            throw ValidationException::withMessages([$field => 'The minimum amount is '.self::format($minimum).'.']);
        }

        if ($maximum !== null && $amount->isGreaterThan($maximum)) {
            throw ValidationException::withMessages([$field => 'The maximum amount is '.self::format($maximum).'.']);
        }

        return self::toRequest($amount);
    }

    /**
     * Human readable amount with thousands separators, e.g. "1,500.00".
     */
    public static function format(mixed $value, int $decimals = 2): string
    {
        $decimal = self::of($value);

        if ($decimal === null) {
            return '—';
        }

        $scaled = $decimal->toScale($decimals, RoundingMode::HALF_UP)->__toString();
        $negative = str_starts_with($scaled, '-');
        $scaled = ltrim($scaled, '-');
        [$integer, $fraction] = array_pad(explode('.', $scaled, 2), 2, null);
        $integer = strrev(implode(',', str_split(strrev($integer), 3)));

        return ($negative ? '-' : '').$integer.($fraction !== null ? '.'.$fraction : '');
    }

    private static function require(mixed $value): BigDecimal
    {
        return self::of($value) ?? throw new \InvalidArgumentException('Invalid amount: '.var_export($value, true));
    }
}
