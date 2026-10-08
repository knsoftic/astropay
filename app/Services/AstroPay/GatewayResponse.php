<?php

namespace App\Services\AstroPay;

/**
 * A successful (`code: 1000`) AstroPay response envelope.
 */
final class GatewayResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly array $data,
        public readonly string $message,
        public readonly int $httpStatus,
        public readonly array $body,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }
}
