<?php

namespace App\Exceptions\AstroPay;

/**
 * The integration is not configured for the requested operation
 * (missing credentials, non-HTTPS callback URL in production, ...).
 */
class ConfigurationException extends AstroPayException
{
    public function userMessage(): string
    {
        return 'This payment option is not available right now.';
    }
}
