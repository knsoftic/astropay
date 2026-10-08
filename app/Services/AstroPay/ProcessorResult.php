<?php

namespace App\Services\AstroPay;

use App\Models\AstroPayTransaction;

final class ProcessorResult
{
    public function __construct(
        public readonly AstroPayTransaction $transaction,
        public readonly UpdateOutcome $outcome,
    ) {}
}
