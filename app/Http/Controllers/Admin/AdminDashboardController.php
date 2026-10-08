<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\AstroPayException;
use App\Http\Controllers\Controller;
use App\Models\AstroPayTransaction;
use App\Models\AstroPayWebhookLog;
use App\Services\AstroPay\AstroPayManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(AstroPayManager $astropay): View
    {
        return view('admin.dashboard', $this->summary($astropay));
    }

    /**
     * POST /v1/account/balance for one currency account, fetched on demand.
     */
    public function balance(Request $request, AstroPayManager $astropay, string $currency): View
    {
        $currency = Currency::tryFrom(strtoupper($currency));
        abort_if($currency === null, 404);

        $balance = null;
        $balanceError = null;

        try {
            $balance = $astropay->client($currency)->balance()->data;
        } catch (AstroPayException $e) {
            $balanceError = $e->getMessage();
        }

        return view('admin.dashboard', $this->summary($astropay) + [
            'balanceCurrency' => $currency,
            'balance' => $balance,
            'balanceError' => $balanceError,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(AstroPayManager $astropay): array
    {
        $counts = AstroPayTransaction::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'currencies' => Currency::cases(),
            'enabled' => $astropay->enabledCurrencies(),
            'configured' => $astropay->configuredCurrencies(),
            'awaitingApproval' => (int) ($counts[TransactionStatus::AwaitingApproval->value] ?? 0),
            'unknown' => (int) ($counts[TransactionStatus::Unknown->value] ?? 0) + (int) ($counts[TransactionStatus::Initiated->value] ?? 0),
            'pending' => (int) ($counts[TransactionStatus::Pending->value] ?? 0),
            'needsReview' => AstroPayTransaction::query()->where('needs_review', true)->count(),
            'failedWebhooks' => AstroPayWebhookLog::query()->where('http_status', '>=', 300)->where('created_at', '>=', now()->subDay())->count(),
            'requiresApproval' => $astropay->requiresPayoutApproval(),
            'callbackPreview' => $this->callbackPreview($astropay),
        ];
    }

    private function callbackPreview(AstroPayManager $astropay): ?string
    {
        try {
            return $astropay->callbackUrl(TransactionType::Deposit, Currency::INR);
        } catch (AstroPayException $e) {
            return 'Invalid: '.$e->getMessage();
        }
    }
}
