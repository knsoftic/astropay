<?php

namespace App\Http\Controllers;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\AstroPayException;
use App\Http\Requests\WithdrawalRequest;
use App\Services\AstroPay\AstroPayManager;
use App\Services\AstroPay\PayoutService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WithdrawalController extends Controller
{
    public function create(Request $request, AstroPayManager $astropay, WalletService $wallets): View
    {
        $currencies = $astropay->enabledCurrencies();
        $userId = $request->user()->getKey();

        return view('withdrawals.create', [
            'currencies' => $currencies,
            'methods' => collect($currencies)->mapWithKeys(fn (Currency $c) => [
                $c->value => array_map(fn ($m) => ['value' => $m->value, 'label' => $m->label()], $c->paymentMethods()),
            ])->all(),
            'balances' => collect($currencies)->mapWithKeys(fn (Currency $c) => [$c->value => $wallets->balance($userId, $c)]),
            'limits' => collect($currencies)->mapWithKeys(fn (Currency $c) => [$c->value => $astropay->limits($c, TransactionType::Payout)]),
            'requiresApproval' => $astropay->requiresPayoutApproval(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(WithdrawalRequest $request, PayoutService $payouts): RedirectResponse
    {
        try {
            $transaction = $payouts->request(
                user: $request->user(),
                currency: $request->currency(),
                amount: $request->validated('amount'),
                method: $request->paymentMethod(),
                beneficiary: $request->beneficiary(),
                idempotencyKey: $request->validated('idempotency_key'),
            );
        } catch (AstroPayException $e) {
            report($e);

            return back()->withInput($request->except('idempotency_key'))->with('error', $e->userMessage());
        }

        $redirect = redirect()->route('transactions.show', $transaction);

        return match ($transaction->status) {
            TransactionStatus::AwaitingApproval => $redirect->with('success', 'Withdrawal requested. The amount is on hold and will be sent after approval.'),
            TransactionStatus::Pending, TransactionStatus::Initiated => $redirect->with('success', 'Withdrawal sent to the payment provider. You will see the result here once it settles.'),
            TransactionStatus::Unknown => $redirect->with('warning', 'The withdrawal is being confirmed with the payment provider. Check its status in a few minutes.'),
            TransactionStatus::Success => $redirect->with('success', 'Withdrawal completed.'),
            TransactionStatus::Failed, TransactionStatus::Rejected => $redirect->with('error', ($transaction->userErrorMessage() ?? 'The withdrawal could not be processed.').' The amount was returned to your wallet.'),
        };
    }
}
