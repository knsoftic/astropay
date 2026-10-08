<?php

namespace App\Http\Controllers;

use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\AstroPayException;
use App\Models\AstroPayTransaction;
use App\Services\AstroPay\AstroPayManager;
use App\Services\AstroPay\StatusSyncService;
use App\Services\AstroPay\UpdateOutcome;
use App\Services\AstroPay\UtrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(TransactionType::class)],
        ]);

        $transactions = AstroPayTransaction::query()
            ->where('user_id', $request->user()->getKey())
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->latest('id')
            ->simplePaginate(20)
            ->withQueryString();

        return view('transactions.index', [
            'transactions' => $transactions,
            'type' => $filters['type'] ?? null,
        ]);
    }

    public function show(Request $request, AstroPayTransaction $transaction): View
    {
        $this->authorize('view', $transaction);

        return view('transactions.show', [
            'transaction' => $transaction,
            'entries' => $transaction->walletEntries()->oldest('id')->get(),
        ]);
    }

    /**
     * Manual status check (POST /v1/payins/query or /v1/payouts/query).
     */
    public function check(Request $request, AstroPayTransaction $transaction, StatusSyncService $sync, AstroPayManager $astropay): RedirectResponse
    {
        $this->authorize('interact', $transaction);

        $key = 'astropay-check:'.$transaction->getKey();
        $cooldown = $astropay->statusCheckCooldown();

        if ($cooldown > 0 && RateLimiter::tooManyAttempts($key, 1)) {
            return back()->with('warning', sprintf('Please wait %d seconds before checking again.', RateLimiter::availableIn($key)));
        }

        if ($cooldown > 0) {
            RateLimiter::hit($key, $cooldown);
        }

        try {
            $result = $sync->sync($transaction);
        } catch (AstroPayException $e) {
            return back()->with('warning', $e->userMessage());
        }

        $status = $result->transaction->status->label();

        return back()->with(
            $result->outcome === UpdateOutcome::Updated ? 'success' : 'info',
            $result->outcome === UpdateOutcome::Updated ? "Status updated: {$status}." : "No change. Current status: {$status}.",
        );
    }

    /**
     * Send the customer back to AstroPay's hosted checkout while the link is still valid.
     */
    public function pay(Request $request, AstroPayTransaction $transaction): RedirectResponse
    {
        $this->authorize('interact', $transaction);

        if (! $transaction->canContinuePayment()) {
            return redirect()->route('transactions.show', $transaction)
                ->with('error', 'This payment link is no longer available. Please start a new deposit.');
        }

        return redirect()->away((string) $transaction->pay_url);
    }

    /**
     * Customer-reported UPI reference for an INR deposit (UTR supplement).
     */
    public function submitUtr(Request $request, AstroPayTransaction $transaction, UtrService $utr): RedirectResponse
    {
        $this->authorize('interact', $transaction);

        $data = $request->validate(['utr' => ['required', 'string', 'max:32']]);

        try {
            $utr->supplement($transaction, $data['utr']);
        } catch (AstroPayException $e) {
            return back()->withInput()->with('error', $e->userMessage());
        }

        return back()->with('success', 'Thank you. The reference was sent to the payment provider; use "Check status" in a few minutes.');
    }
}
