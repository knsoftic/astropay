<?php

namespace App\Http\Controllers;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\AstroPayException;
use App\Http\Requests\DepositRequest;
use App\Models\AstroPayTransaction;
use App\Services\AstroPay\AstroPayManager;
use App\Services\AstroPay\DepositService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DepositController extends Controller
{
    public function create(Request $request, AstroPayManager $astropay): View
    {
        $currencies = $astropay->enabledCurrencies();

        $lastPhone = AstroPayTransaction::query()
            ->where('user_id', $request->user()->getKey())
            ->where('type', TransactionType::Deposit->value)
            ->whereNotNull('customer_phone')
            ->latest('id')
            ->value('customer_phone');

        $requested = Currency::tryFrom(strtoupper((string) $request->query('currency', '')));

        return view('deposits.create', [
            'currencies' => $currencies,
            'selected' => in_array($requested, $currencies, true) ? $requested->value : ($currencies[0]->value ?? null),
            'methods' => $this->methodsByCurrency($currencies),
            'limits' => collect($currencies)->mapWithKeys(fn (Currency $c) => [$c->value => $astropay->limits($c, TransactionType::Deposit)]),
            'lastPhone' => $lastPhone,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(DepositRequest $request, DepositService $deposits): RedirectResponse
    {
        $user = $request->user();

        try {
            $transaction = $deposits->create(
                user: $user,
                currency: $request->currency(),
                amount: $request->validated('amount'),
                customer: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $request->validated('phone'),
                ],
                method: $request->paymentMethod(),
                idempotencyKey: $request->validated('idempotency_key'),
            );
        } catch (AstroPayException $e) {
            report($e);

            return back()->withInput($request->except('idempotency_key'))->with('error', $e->userMessage());
        }

        if ($transaction->canContinuePayment()) {
            // Hosted checkout page rendered and managed by AstroPay.
            return redirect()->away((string) $transaction->pay_url);
        }

        return match ($transaction->status) {
            TransactionStatus::Rejected, TransactionStatus::Failed => back()
                ->withInput($request->except('idempotency_key'))
                ->with('error', $transaction->userErrorMessage() ?? 'The deposit could not be started. Please try again.'),
            TransactionStatus::Unknown, TransactionStatus::Initiated => redirect()
                ->route('transactions.show', $transaction)
                ->with('warning', 'We could not confirm the deposit with the payment provider yet. Use "Check status" in a minute; do not pay twice.'),
            TransactionStatus::Success => redirect()
                ->route('transactions.show', $transaction)
                ->with('success', 'This deposit has already been completed.'),
            default => redirect()
                ->route('transactions.show', $transaction)
                ->with('error', $transaction->userErrorMessage() ?? 'The payment link is no longer available. Please start a new deposit.'),
        };
    }

    /**
     * @param  list<Currency>  $currencies
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function methodsByCurrency(array $currencies): array
    {
        $methods = [];

        foreach ($currencies as $currency) {
            $methods[$currency->value] = array_map(
                fn ($method) => ['value' => $method->value, 'label' => $method->label()],
                $currency->paymentMethods(),
            );
        }

        return $methods;
    }
}
