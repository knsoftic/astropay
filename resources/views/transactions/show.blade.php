@extends('layouts.app')
@use('App\Enums\AstroPay\TransactionStatus')
@use('App\Services\AstroPay\Support\Amount')

@section('title', $transaction->type->label())

@section('content')
    <h1>{{ $transaction->type->label() }} · {{ Amount::format($transaction->amount) }} {{ $transaction->currency->value }}</h1>

    @if ($message = $transaction->userErrorMessage())
        <div class="alert alert-warning">{{ $message }}</div>
    @endif

    <div class="card">
        <dl class="details">
            <dt>Status</dt>
            <dd>@include('partials.status-badge', ['transaction' => $transaction])</dd>

            <dt>Reference</dt>
            <dd class="mono">{{ $transaction->order_id }}</dd>

            <dt>Currency</dt>
            <dd>{{ $transaction->currency->label() }}</dd>

            <dt>Method</dt>
            <dd>{{ $transaction->payment_method?->label() ?? ($transaction->currency->usesPaymentMethods() ? 'Automatic' : 'USDT (TRC20)') }}</dd>

            <dt>Amount</dt>
            <dd>{{ Amount::format($transaction->amount) }} {{ $transaction->currency->value }}</dd>

            @if ($transaction->isPayout())
                <dt>Beneficiary</dt>
                <dd>
                    {{ $transaction->beneficiary_name ?? '—' }}<br>
                    <span class="mono">{{ $transaction->maskedBeneficiaryAccount() }}</span>
                    @if ($transaction->beneficiary_bank_code)
                        <span class="muted">· IFSC {{ $transaction->beneficiary_bank_code }}</span>
                    @endif
                </dd>
            @endif

            @if ($transaction->utr)
                <dt>Bank reference (UTR)</dt>
                <dd class="mono">{{ $transaction->utr }}</dd>
            @endif

            @if ($transaction->isPayout() && $transaction->status === TransactionStatus::Failed && $transaction->remark)
                <dt>Failure reason</dt>
                <dd>{{ $transaction->remark }}</dd>
            @endif

            <dt>Created</dt>
            <dd>{{ $transaction->created_at->format('Y-m-d H:i:s') }}</dd>

            @if ($transaction->completed_at)
                <dt>Completed</dt>
                <dd>{{ $transaction->completed_at->format('Y-m-d H:i:s') }}</dd>
            @endif
        </dl>
    </div>

    @if ($transaction->user_id === auth()->id())
        <div class="actions" style="margin-bottom:20px">
            @if ($transaction->canContinuePayment())
                <a class="btn" href="{{ route('transactions.pay', $transaction) }}">Continue payment</a>
            @endif

            @if ($transaction->canCheckStatus() && ! $transaction->status->isFinal())
                <form method="POST" action="{{ route('transactions.check', $transaction) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Check status</button>
                </form>
            @endif

            @if ($transaction->status === TransactionStatus::AwaitingApproval)
                <span class="muted">Your withdrawal is waiting for approval. The amount is on hold.</span>
            @endif
        </div>

        @if ($transaction->canSubmitUtr())
            <div class="card">
                <h2>Paid but not confirmed?</h2>
                <p class="muted">If you completed the UPI payment, enter the 12-digit UTR / reference number shown in your bank or UPI app.</p>
                <form method="POST" action="{{ route('transactions.utr', $transaction) }}" class="actions">
                    @csrf
                    <input type="text" name="utr" value="{{ old('utr') }}" required maxlength="32" placeholder="e.g. 437558231943" style="max-width:260px" autocomplete="off">
                    <button type="submit" class="btn btn-secondary">Submit UTR</button>
                </form>
                @error('utr')<div class="error-text">{{ $message }}</div>@enderror
            </div>
        @endif
    @endif

    @if ($entries->isNotEmpty())
        <div class="card">
            <h2>Wallet movements</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th class="num">Balance after</th></tr></thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td>{{ $entry->created_at->format('Y-m-d H:i') }}</td>
                            <td>{{ $entry->type->label() }}</td>
                            <td class="num">{{ Amount::format($entry->amount) }}</td>
                            <td class="num">{{ Amount::format($entry->balance_after) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <p><a href="{{ route('transactions.index') }}">← All transactions</a></p>
@endsection
