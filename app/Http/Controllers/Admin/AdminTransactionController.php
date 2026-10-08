<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\AstroPayException;
use App\Exceptions\AstroPay\InvalidTransactionStateException;
use App\Http\Controllers\Controller;
use App\Models\AstroPayTransaction;
use App\Services\AstroPay\PayoutService;
use App\Services\AstroPay\StatusSyncService;
use App\Services\AstroPay\TransactionProcessor;
use App\Services\AstroPay\UpdateOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminTransactionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'currency' => ['nullable', Rule::enum(Currency::class)],
            'review' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:64'],
        ]);

        $transactions = AstroPayTransaction::query()
            ->with('user:id,name,email')
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['currency'] ?? null, fn ($q, $v) => $q->where('currency', $v))
            ->when($request->boolean('review'), fn ($q) => $q->where('needs_review', true))
            ->when(filled($filters['q'] ?? null), function ($q) use ($filters) {
                $term = trim((string) $filters['q']);
                $q->where(fn ($inner) => $inner->where('order_id', $term)->orWhere('utr', $term)->orWhere('uuid', $term));
            })
            ->latest('id')
            ->simplePaginate(30)
            ->withQueryString();

        return view('admin.transactions.index', [
            'transactions' => $transactions,
            'filters' => $filters,
        ]);
    }

    public function show(AstroPayTransaction $transaction): View
    {
        $transaction->load(['user', 'approver', 'rejecter', 'reviewer']);

        return view('admin.transactions.show', [
            'transaction' => $transaction,
            'entries' => $transaction->walletEntries()->oldest('id')->get(),
            'webhooks' => $transaction->webhookLogs()->latest('id')->limit(20)->get(),
        ]);
    }

    public function check(AstroPayTransaction $transaction, StatusSyncService $sync): RedirectResponse
    {
        try {
            $result = $sync->sync($transaction, 'admin status check');
        } catch (AstroPayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            $result->outcome === UpdateOutcome::Conflict ? 'warning' : 'success',
            match ($result->outcome) {
                UpdateOutcome::Updated => 'Status updated: '.$result->transaction->status->label().'.',
                UpdateOutcome::Unchanged => 'No change. AstroPay status matches: '.$result->transaction->status->label().'.',
                UpdateOutcome::Conflict => 'AstroPay reports a conflicting status. The transaction was flagged for review.',
            },
        );
    }

    public function approve(Request $request, AstroPayTransaction $transaction, PayoutService $payouts): RedirectResponse
    {
        // Serialise approvals of the same payout across requests (double clicks, two admins).
        $lock = Cache::lock('astropay-approve:'.$transaction->getKey(), 60);

        if (! $lock->get()) {
            return back()->with('warning', 'This withdrawal is already being processed.');
        }

        try {
            $transaction = $payouts->approve($transaction, $request->user());
        } catch (InvalidTransactionStateException $e) {
            return back()->with('error', $e->getMessage());
        } finally {
            $lock->release();
        }

        return back()->with(...match ($transaction->status) {
            TransactionStatus::Pending => ['success', 'Withdrawal sent to AstroPay. It will settle via callback or a status check.'],
            TransactionStatus::Success => ['success', 'Withdrawal completed.'],
            TransactionStatus::Unknown, TransactionStatus::Initiated => ['warning', 'AstroPay did not give a definitive answer ('.$transaction->error_message.'). Do NOT approve again; use "Check status" after a few minutes.'],
            TransactionStatus::AwaitingApproval => ['error', 'AstroPay refused the withdrawal: '.$transaction->error_message.'. Fix the cause and approve again, or reject it.'],
            TransactionStatus::Failed, TransactionStatus::Rejected => ['error', 'Withdrawal failed: '.($transaction->error_message ?? $transaction->remark ?? 'unknown reason').'. The amount was refunded to the user.'],
        });
    }

    public function reject(Request $request, AstroPayTransaction $transaction, PayoutService $payouts): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            $payouts->reject($transaction, $request->user(), $data['reason']);
        } catch (InvalidTransactionStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Withdrawal rejected and the amount refunded to the user.');
    }

    public function resolve(Request $request, AstroPayTransaction $transaction, TransactionProcessor $processor): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in([
                TransactionProcessor::REVIEW_ACTION_CREDIT,
                TransactionProcessor::REVIEW_ACTION_RECLAIM,
                TransactionProcessor::REVIEW_ACTION_DISMISS,
            ])],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $processor->resolveReview($transaction, $request->user(), $data['action'], $data['note']);
        } catch (InvalidTransactionStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Review resolved.');
    }
}
