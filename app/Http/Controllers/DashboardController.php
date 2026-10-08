<?php

namespace App\Http\Controllers;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionStatus;
use App\Models\AstroPayTransaction;
use App\Services\AstroPay\AstroPayManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AstroPayManager $astropay): View
    {
        $user = $request->user();
        $wallets = $user->wallets()->get()->keyBy(fn ($wallet) => $wallet->currency->value);

        // Show every enabled currency plus any currency the user still holds a balance in.
        $currencies = collect(Currency::cases())->filter(
            fn (Currency $currency) => $astropay->isEnabled($currency) || $wallets->has($currency->value),
        );

        $balances = $currencies->mapWithKeys(fn (Currency $currency) => [
            $currency->value => $wallets->get($currency->value)?->balance ?? '0.0000',
        ]);

        $transactions = AstroPayTransaction::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->limit(10)
            ->get();

        $inProgress = AstroPayTransaction::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', [
                TransactionStatus::AwaitingApproval->value,
                TransactionStatus::Initiated->value,
                TransactionStatus::Pending->value,
                TransactionStatus::Unknown->value,
            ])
            ->count();

        return view('dashboard', [
            'balances' => $balances,
            'enabled' => collect($astropay->enabledCurrencies())->map(fn (Currency $c) => $c->value)->all(),
            'transactions' => $transactions,
            'inProgress' => $inProgress,
            'hasEnabledCurrency' => $astropay->enabledCurrencies() !== [],
        ]);
    }
}
