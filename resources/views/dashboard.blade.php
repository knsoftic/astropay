@extends('layouts.app')
@use('App\Enums\AstroPay\Currency')
@use('App\Services\AstroPay\Support\Amount')

@section('title', 'Dashboard')
@section('subtitle', now()->format('l, d F Y'))

@section('content')
    <div class="stack">
        <section class="hero">
            <div class="row-between" style="align-items:flex-start">
                <div>
                    <h2>Welcome back, {{ \Illuminate\Support\Str::before(trim(auth()->user()->name).' ', ' ') }}</h2>
                    <p>
                        @if ($inProgress > 0)
                            You have {{ $inProgress }} {{ \Illuminate\Support\Str::plural('transaction', $inProgress) }} in progress.
                        @else
                            All your transactions are settled.
                        @endif
                    </p>
                </div>
                <span class="badge no-dot" style="background:rgba(255,255,255,.16);color:#fff"><x-icon name="lock" class="icon-sm" /> Secure payments</span>
            </div>
            <div class="hero-actions">
                <a class="btn btn-light" href="{{ route('deposits.create') }}"><x-icon name="deposit" /> Deposit</a>
                <a class="btn btn-glass" href="{{ route('withdrawals.create') }}"><x-icon name="withdraw" /> Withdraw</a>
                <a class="btn btn-glass" href="{{ route('transactions.index') }}"><x-icon name="list" /> History</a>
            </div>
        </section>

        @unless ($hasEnabledCurrency)
            <div class="alert alert-warning mb-0">
                <x-icon name="alert" />
                <div class="body">No payment currency is available right now, so deposits and withdrawals are paused.</div>
            </div>
        @endunless

        @if ($balances->isNotEmpty())
            <div class="grid-cards">
                @foreach ($balances as $code => $balance)
                    @php($currency = Currency::from($code))
                    <div class="balance-card">
                        <span class="glow glow-{{ $code }}"></span>
                        <div class="top">
                            <x-coin :currency="$currency" />
                            <div>
                                <div class="name">{{ $code }} wallet</div>
                                <div class="label">{{ $currency->label() }}</div>
                            </div>
                        </div>
                        <div class="label">Available balance</div>
                        <div class="amount">{{ Amount::format($balance) }}<small>{{ $code }}</small></div>
                        @if (in_array($code, $enabled, true))
                            <div class="balance-actions">
                                <a class="btn btn-secondary btn-sm" href="{{ route('deposits.create', ['currency' => $code]) }}"><x-icon name="deposit" /> Deposit</a>
                                <a class="btn btn-secondary btn-sm" href="{{ route('withdrawals.create', ['currency' => $code]) }}"><x-icon name="withdraw" /> Withdraw</a>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="card card-flush">
            <div class="card-head">
                <div>
                    <div class="card-title"><x-icon name="activity" /> Recent activity</div>
                    <div class="card-desc">Your last 10 deposits and withdrawals</div>
                </div>
                <a class="btn btn-ghost btn-sm" href="{{ route('transactions.index') }}">View all <x-icon name="chevron-right" /></a>
            </div>
            @include('transactions._table', ['transactions' => $transactions])
        </div>
    </div>
@endsection
