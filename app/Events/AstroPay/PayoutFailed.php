<?php

namespace App\Events\AstroPay;

use App\Models\AstroPayTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payout failed or was rejected; any held wallet amount has been refunded.
 */
class PayoutFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public AstroPayTransaction $transaction) {}
}
