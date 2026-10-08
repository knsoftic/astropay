@extends('layouts.app')
@use('App\Enums\AstroPay\TransactionStatus')
@use('App\Services\AstroPay\Support\Amount')

@section('title', $transaction->type->label().' details')
@section('subtitle', 'Reference '.$transaction->order_id)

@section('actions')
    <a class="btn btn-secondary" href="{{ route('transactions.index') }}"><x-icon name="arrow-left" /> <span>All transactions</span></a>
@endsection

@section('content')
    @php($isOwner = $transaction->user_id === auth()->id())

    @if ($message = $transaction->userErrorMessage())
        <div class="alert alert-warning">
            <x-icon name="alert" />
            <div class="body">{{ $message }}</div>
        </div>
    @endif

    <div class="layout-side">
        <div class="stack">
            <div class="card">
                <div class="tx-hero">
                    <span class="tx-icon {{ $transaction->isDeposit() ? 'tx-in' : 'tx-out' }}"><x-icon :name="$transaction->isDeposit() ? 'deposit' : 'withdraw'" /></span>
                    <div style="flex:1;min-width:0">
                        <div class="muted small">{{ $transaction->type->label() }} · {{ $transaction->created_at->format('d M Y, H:i') }}</div>
                        <div class="big">{{ Amount::format($transaction->amount) }}<small>{{ $transaction->currency->value }}</small></div>
                    </div>
                    @include('partials.status-badge', ['transaction' => $transaction])
                </div>
                @include('partials.timeline', ['transaction' => $transaction])
            </div>

            <div class="card">
                <div class="card-title" style="margin-bottom:8px"><x-icon name="file" /> Details</div>
                <dl class="dl">
                    <dt>Reference</dt>
                    <dd>
                        <span class="mono">{{ $transaction->order_id }}</span>
                        <button type="button" class="link-btn small" data-copy="{{ $transaction->order_id }}" style="margin-left:8px"><span data-copy-label>Copy</span></button>
                    </dd>

                    <dt>Currency</dt>
                    <dd><span class="row" style="gap:8px"><x-coin :currency="$transaction->currency" size="sm" /> {{ $transaction->currency->label() }}</span></dd>

                    <dt>Method</dt>
                    <dd>{{ $transaction->payment_method?->label() ?? ($transaction->currency->usesPaymentMethods() ? 'Automatic' : 'USDT (TRC20)') }}</dd>

                    @if ($transaction->isPayout())
                        <dt>Beneficiary</dt>
                        <dd>
                            {{ $transaction->beneficiary_name ?? '—' }}
                            <div class="mono muted">{{ $transaction->maskedBeneficiaryAccount() }}@if ($transaction->beneficiary_bank_code) · IFSC {{ $transaction->beneficiary_bank_code }}@endif</div>
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
                    <dd>{{ $transaction->created_at->format('d M Y, H:i:s') }}</dd>

                    @if ($transaction->completed_at)
                        <dt>Completed</dt>
                        <dd>{{ $transaction->completed_at->format('d M Y, H:i:s') }}</dd>
                    @endif
                </dl>
            </div>

            @if ($entries->isNotEmpty())
                <div class="card card-flush">
                    <div class="card-head"><div class="card-title"><x-icon name="wallet" /> Wallet movements</div></div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th class="num">Balance after</th></tr></thead>
                            <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td class="nowrap">{{ $entry->created_at->format('d M Y, H:i') }}</td>
                                    <td>{{ $entry->type->label() }}</td>
                                    <td class="num"><span class="{{ str_starts_with((string) $entry->amount, '-') ? 'amount-out' : 'amount-in' }}">{{ Amount::format($entry->amount) }}</span></td>
                                    <td class="num">{{ Amount::format($entry->balance_after) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <aside class="sticky stack">
            @if ($isOwner)
                <div class="card">
                    <div class="card-title" style="margin-bottom:14px"><x-icon name="zap" /> Actions</div>
                    <div class="stack" style="gap:10px">
                        @if ($transaction->canContinuePayment())
                            <a class="btn btn-primary btn-block" href="{{ route('transactions.pay', $transaction) }}"><x-icon name="external" /> Continue payment</a>
                        @endif

                        @if ($transaction->canCheckStatus() && ! $transaction->status->isFinal())
                            <form method="POST" action="{{ route('transactions.check', $transaction) }}" data-lock>
                                @csrf
                                <button type="submit" class="btn btn-secondary btn-block"><x-icon name="refresh" /> Check status</button>
                            </form>
                        @endif

                        @if ($transaction->status === TransactionStatus::AwaitingApproval)
                            <div class="callout"><x-icon name="clock" /><div>Your withdrawal is waiting for approval. The amount is on hold in your wallet.</div></div>
                        @elseif ($transaction->status->isFinal())
                            <div class="callout"><x-icon name="info" /><div>This transaction is complete. No further action is needed.</div></div>
                        @endif
                    </div>
                </div>

                @if ($transaction->canSubmitUtr())
                    <div class="card">
                        <div class="card-title" style="margin-bottom:6px"><x-icon name="hash" /> Paid but not confirmed?</div>
                        <p class="muted small">Enter the 12-digit UTR / reference number from your bank or UPI app.</p>
                        <form method="POST" action="{{ route('transactions.utr', $transaction) }}" data-lock>
                            @csrf
                            <div class="field">
                                <input type="text" name="utr" value="{{ old('utr') }}" required maxlength="32" placeholder="e.g. 437558231943" autocomplete="off" aria-label="UTR" @class(['is-invalid' => $errors->has('utr')])>
                                @error('utr')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-secondary btn-block">Submit UTR</button>
                        </form>
                    </div>
                @endif
            @endif

            <div class="card">
                <div class="card-title" style="margin-bottom:10px"><x-icon name="info" /> Need help?</div>
                <p class="muted small mb-0">Share the reference <span class="mono">{{ $transaction->order_id }}</span> with support so we can find this transaction quickly.</p>
            </div>
        </aside>
    </div>
@endsection
