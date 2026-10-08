<?php

namespace App\Services\AstroPay;

use App\Models\AstroPayTransaction;

final class WebhookResult
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $outcome,
        public readonly string $message,
        public readonly ?AstroPayTransaction $transaction = null,
    ) {}

    public function successful(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }
}
