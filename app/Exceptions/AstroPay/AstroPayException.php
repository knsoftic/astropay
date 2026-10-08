<?php

namespace App\Exceptions\AstroPay;

use RuntimeException;

/**
 * Base class for every AstroPay integration error.
 */
class AstroPayException extends RuntimeException
{
    /**
     * Message that is safe to show to an end user.
     */
    public function userMessage(): string
    {
        return 'The payment service is temporarily unavailable. Please try again shortly.';
    }
}
