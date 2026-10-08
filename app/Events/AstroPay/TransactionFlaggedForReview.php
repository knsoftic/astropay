<?php

namespace App\Events\AstroPay;

use App\Models\AstroPayTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A transaction needs manual review (amount mismatch or conflicting status).
 */
class TransactionFlaggedForReview implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public AstroPayTransaction $transaction) {}
}
