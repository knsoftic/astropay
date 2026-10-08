<?php

namespace App\Events\AstroPay;

use App\Models\AstroPayTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A deposit settled successfully on AstroPay.
 */
class DepositSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public AstroPayTransaction $transaction) {}
}
