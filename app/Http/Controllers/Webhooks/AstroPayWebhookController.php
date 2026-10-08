<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionType;
use App\Http\Controllers\Controller;
use App\Services\AstroPay\WebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/astropay/{type}/{currency}
 *
 * The URL is the callbackUrl sent with each order. AstroPay only needs an
 * HTTP 2xx; any other status is logged on their side as a failed delivery.
 */
class AstroPayWebhookController extends Controller
{
    public function __invoke(Request $request, string $type, string $currency, WebhookHandler $handler): Response
    {
        $result = $handler->handle($request, TransactionType::from($type), Currency::from($currency));

        return response($result->successful() ? 'success' : 'fail: '.$result->outcome, $result->httpStatus)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
