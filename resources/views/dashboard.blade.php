@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1>Wallet</h1>

    @unless ($hasEnabledCurrency)
        <div class="alert alert-warning">No payment currency is configured yet. Deposits and withdrawals are unavailable.</div>
    @endunless

    <div class="grid">
        @forelse ($balances as $currency => $balance)
            <div class="stat">
                <div class="label">{{ $currency }} balance</div>
                <div class="value">{{ \App\Services\AstroPay\Support\Amount::format($balance) }}</div>
            </div>
        @empty
            <div class="stat"><div class="label">No wallets yet</div></div>
        @endforelse
    </div>

    <div class="actions" style="margin-bottom:20px">
        <a class="btn" href="{{ route('deposits.create') }}">Deposit</a>
        <a class="btn btn-secondary" href="{{ route('withdrawals.create') }}">Withdraw</a>
    </div>

    <div class="card">
        <h2>Recent transactions</h2>
        @include('transactions._table', ['transactions' => $transactions])
        <p><a href="{{ route('transactions.index') }}">All transactions →</a></p>
    </div>
@endsection
