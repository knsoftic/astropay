<?php

namespace App\Services\AstroPay\Support;

use App\Enums\AstroPay\TransactionType;
use Illuminate\Support\Str;

/**
 * Merchant-generated orderId. AstroPay does not de-duplicate orderIds, so a
 * new one is generated for every create attempt.
 */
final class OrderId
{
    public static function generate(TransactionType $type): string
    {
        $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) config('astropay.order_prefix', 'GP')));
        $prefix = substr($prefix !== '' ? $prefix : 'GP', 0, 8);

        return $prefix.($type === TransactionType::Deposit ? 'D' : 'W').strtoupper((string) Str::ulid());
    }
}
