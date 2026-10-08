@extends('layouts.app')

@section('title', 'Transactions')
@section('subtitle', 'Every deposit and withdrawal on your account')

@section('actions')
    <a class="btn btn-secondary" href="{{ route('withdrawals.create') }}"><x-icon name="withdraw" /> <span>Withdraw</span></a>
    <a class="btn btn-primary" href="{{ route('deposits.create') }}"><x-icon name="deposit" /> <span>Deposit</span></a>
@endsection

@section('content')
    <div class="card card-flush">
        <div class="card-head">
            <nav class="segmented" aria-label="Filter">
                <a @class(['active' => $type === null]) href="{{ route('transactions.index') }}">All</a>
                <a @class(['active' => $type === 'deposit']) href="{{ route('transactions.index', ['type' => 'deposit']) }}">Deposits</a>
                <a @class(['active' => $type === 'payout']) href="{{ route('transactions.index', ['type' => 'payout']) }}">Withdrawals</a>
            </nav>
        </div>
        @include('transactions._table', ['transactions' => $transactions])
        @include('partials.pagination', ['paginator' => $transactions])
    </div>
@endsection
