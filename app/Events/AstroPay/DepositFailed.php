<?php

namespace App\Events\AstroPay;

use App\Models\AstroPayTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AstroPay reported a deposit as failed.
 */
class DepositFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public AstroPayTransaction $transaction) {}
}
