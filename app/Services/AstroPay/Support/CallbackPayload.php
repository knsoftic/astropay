<?php

namespace App\Services\AstroPay\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Turns a callback request into a flat map of strings, exactly as AstroPay
 * sent them. The docs do not say whether callbacks are JSON or form-encoded,
 * so both are accepted. For JSON, numeric literals are kept verbatim
 * ("500.0000" stays "500.0000", not "500") so the signature still matches.
 */
final class CallbackPayload
{
    private const MAX_FIELDS = 50;

    /**
     * @return array<string, string|null>
     *
     * @throws InvalidArgumentException when the body cannot be parsed
     */
    public static function fromRequest(Request $request): array
    {
        $raw = (string) $request->getContent();
        $trimmed = ltrim($raw);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $request->isJson())) {
            return self::fromJson($raw);
        }

        return self::fromArray($request->request->all() ?: $request->query->all());
    }

    /**
     * @return array<string, string|null>
     */
    public static function fromJson(string $raw): array
    {
        $decoded = json_decode($raw, true, 8, JSON_BIGINT_AS_STRING);

        if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new InvalidArgumentException('Callback body is not a JSON object.');
        }

        $payload = [];

        foreach ($decoded as $key => $value) {
            $key = (string) $key;

            $payload[$key] = match (true) {
                is_string($value) => $value,
                $value === null => null,
                is_int($value), is_float($value) => self::rawNumberLiteral($raw, $key) ?? (string) $value,
                is_bool($value) => $value ? '1' : '0',
                default => null,
            };
        }

        return self::limit($payload);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, string|null>
     */
    public static function fromArray(array $input): array
    {
        $payload = [];

        foreach ($input as $key => $value) {
            $payload[(string) $key] = is_scalar($value) ? (string) $value : null;
        }

        return self::limit($payload);
    }

    private static function rawNumberLiteral(string $raw, string $key): ?string
    {
        $pattern = '/"'.preg_quote($key, '/').'"\s*:\s*(-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)\s*[,}]/';

        return preg_match($pattern, $raw, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string|null>
     */
    private static function limit(array $payload): array
    {
        if (count($payload) > self::MAX_FIELDS) {
            throw new InvalidArgumentException('Callback body has too many fields.');
        }

        return $payload;
    }
}
