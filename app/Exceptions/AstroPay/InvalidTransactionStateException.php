<?php

namespace App\Exceptions\AstroPay;

/**
 * The requested action is not allowed in the transaction's current state
 * (approving an already-sent payout, checking an unsent order, ...).
 */
class InvalidTransactionStateException extends AstroPayException
{
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
