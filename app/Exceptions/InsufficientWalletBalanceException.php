<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientWalletBalanceException extends RuntimeException
{
    public function __construct(public readonly string $available, public readonly string $requested)
    {
        parent::__construct(sprintf('Insufficient wallet balance: %s available, %s requested.', $available, $requested));
    }
}
