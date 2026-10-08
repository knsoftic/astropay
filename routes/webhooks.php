<?php

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionType;
use App\Http\Controllers\Webhooks\AstroPayWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AstroPay callbacks
|--------------------------------------------------------------------------
|
| Loaded without the "web" middleware group: no session, cookies or CSRF.
| Authenticity is proven by the callback signature instead.
|
*/

Route::post('webhooks/astropay/{type}/{currency}', AstroPayWebhookController::class)
    ->whereIn('type', TransactionType::values())
    ->whereIn('currency', Currency::values())
    ->middleware('throttle:astropay-webhooks')
    ->name('astropay.webhook');
