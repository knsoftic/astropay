<?php

/*
|--------------------------------------------------------------------------
| AstroPay
|--------------------------------------------------------------------------
|
| Merchant data is NOT configured here. Merchant keys, limits, the enabled
| flag per currency, the API base URL, the callback base URL and the callback
| IP allowlist are stored in the database (astropay_accounts,
| astropay_settings) and edited in Admin → Settings.
|
| This file only holds how the application behaves.
|
*/

return [

    'timeout' => (int) env('ASTROPAY_TIMEOUT', 30),

    'connect_timeout' => (int) env('ASTROPAY_CONNECT_TIMEOUT', 10),

    // Extra attempts for read-only calls (query/balance/UTR query) on
    // connection errors or a 500/503 response. Create calls are never retried
    // because AstroPay does not de-duplicate orders.
    'query_retries' => (int) env('ASTROPAY_QUERY_RETRIES', 2),

    // Prefix for the merchant-generated orderId (letters and digits only).
    'order_prefix' => env('ASTROPAY_ORDER_PREFIX', 'GP'),

    /*
    |--------------------------------------------------------------------------
    | Deposits
    |--------------------------------------------------------------------------
    */

    'deposits' => [
        // 'gross' credits the user's wallet with the requested amount (the
        // merchant absorbs AstroPay's commission); 'net' credits the amount
        // minus the commission reported by AstroPay.
        'wallet_credit' => env('ASTROPAY_DEPOSIT_WALLET_CREDIT', 'gross'),

        // How long the hosted checkout link is offered again to the customer.
        'pay_url_ttl_minutes' => (int) env('ASTROPAY_PAY_URL_TTL_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    */

    'payouts' => [
        // When true, a user's withdrawal request waits in the admin panel and
        // is only sent to AstroPay after an admin approves it.
        'require_approval' => (bool) env('ASTROPAY_PAYOUT_REQUIRE_APPROVAL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Callbacks (webhooks)
    |--------------------------------------------------------------------------
    */

    'webhooks' => [
        // Re-query AstroPay before applying a final status received in a
        // callback, as the docs recommend before crediting a customer.
        'confirm_via_query' => (bool) env('ASTROPAY_WEBHOOK_CONFIRM_VIA_QUERY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual status checks
    |--------------------------------------------------------------------------
    */

    'status_check' => [
        // An order whose create call timed out is only treated as "never
        // created" when AstroPay still returns 404 after this many minutes.
        'not_found_grace_minutes' => (int) env('ASTROPAY_NOT_FOUND_GRACE_MINUTES', 15),

        // Minimum seconds between two manual status checks of one order.
        'cooldown_seconds' => (int) env('ASTROPAY_STATUS_CHECK_COOLDOWN', 15),
    ],

    'log_channel' => env('ASTROPAY_LOG_CHANNEL', 'astropay'),

];
