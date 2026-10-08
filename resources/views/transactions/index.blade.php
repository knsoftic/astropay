@extends('layouts.app')

@section('title', 'Transactions')

@section('content')
    <h1>Transactions</h1>

    <div class="actions" style="margin-bottom:16px">
        <a @class(['btn', 'btn-secondary' => $type !== null]) href="{{ route('transactions.index') }}">All</a>
        <a @class(['btn', 'btn-secondary' => $type !== 'deposit']) href="{{ route('transactions.index', ['type' => 'deposit']) }}">Deposits</a>
        <a @class(['btn', 'btn-secondary' => $type !== 'payout']) href="{{ route('transactions.index', ['type' => 'payout']) }}">Withdrawals</a>
    </div>

    <div class="card">
        @include('transactions._table', ['transactions' => $transactions])
        @include('partials.pagination', ['paginator' => $transactions])
    </div>
@endsection
