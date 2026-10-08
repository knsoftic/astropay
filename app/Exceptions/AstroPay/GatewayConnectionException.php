<?php

namespace App\Exceptions\AstroPay;

use Throwable;

/**
 * No usable answer from AstroPay: network failure, timeout, or a response
 * that is not the documented JSON envelope. The outcome of the call is unknown.
 */
class GatewayConnectionException extends AstroPayException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
