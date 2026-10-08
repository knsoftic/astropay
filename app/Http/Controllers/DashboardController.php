<?php

namespace App\Http\Controllers;

use App\Enums\AstroPay\Currency;
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

        return view('dashboard', [
            'balances' => $balances,
            'transactions' => $transactions,
            'hasEnabledCurrency' => $astropay->enabledCurrencies() !== [],
        ]);
    }
}
