@extends('layouts.app')
@use('App\Enums\AstroPay\Currency')
@use('App\Enums\AstroPay\TransactionStatus')
@use('App\Enums\AstroPay\TransactionType')
@use('App\Services\AstroPay\Support\Amount')

@php
    $pageTitle = match (true) {
        ($filters['status'] ?? null) === 'awaiting_approval' => 'Approvals',
        request()->boolean('review') => 'Reviews',
        default => 'All transactions',
    };
@endphp

@section('title', $pageTitle)
@section('subtitle', 'Deposits and withdrawals of all users')

@section('content')
    <form method="GET" action="{{ route('admin.transactions.index') }}" class="card" style="margin-bottom:20px">
        <div class="filters">
            <div class="field grow">
                <label for="q">Search</label>
                <input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="64" placeholder="Order ID, UTR or reference">
            </div>
            <div class="field">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="">All types</option>
                    @foreach (TransactionType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(($filters['type'] ?? null) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    @foreach (TransactionStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="currency">Currency</label>
                <select id="currency" name="currency">
                    <option value="">All</option>
                    @foreach (Currency::cases() as $currency)
                        <option value="{{ $currency->value }}" @selected(($filters['currency'] ?? null) === $currency->value)>{{ $currency->value }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="flex:0 0 auto;min-width:0;padding-bottom:11px">
                <label class="checkbox"><input type="checkbox" name="review" value="1" @checked(request()->boolean('review'))> Needs review</label>
            </div>
            <div class="row" style="gap:8px">
                <button type="submit" class="btn btn-primary"><x-icon name="filter" /> Filter</button>
                <a class="btn btn-ghost" href="{{ route('admin.transactions.index') }}">Reset</a>
            </div>
        </div>
    </form>

    <div class="card card-flush">
        @if ($transactions->isEmpty())
            <x-empty icon="search" title="No transactions match" text="Try another filter or clear the search." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Transaction</th>
                        <th>User</th>
                        <th>Method</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th class="num">Amount</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($transactions as $transaction)
                        <tr class="clickable" onclick="window.location='{{ route('admin.transactions.show', $transaction) }}'">
                            <td>
                                <div class="row">
                                    <span class="tx-icon {{ $transaction->isDeposit() ? 'tx-in' : 'tx-out' }}"><x-icon :name="$transaction->isDeposit() ? 'deposit' : 'withdraw'" /></span>
                                    <div>
                                        <a class="cell-main mono" href="{{ route('admin.transactions.show', $transaction) }}">{{ $transaction->order_id }}</a>
                                        <div class="cell-sub">{{ $transaction->type->label() }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if ($transaction->user)
                                    <div class="cell-main">{{ $transaction->user->name }}</div>
                                    <div class="cell-sub">{{ $transaction->user->email }}</div>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="row" style="gap:8px">
                                    <x-coin :currency="$transaction->currency" size="sm" />
                                    <span>{{ $transaction->payment_method?->value ?? ($transaction->currency->usesPaymentMethods() ? 'AUTO' : 'TRC20') }}</span>
                                </div>
                            </td>
                            <td class="nowrap">
                                <div>{{ $transaction->created_at->format('d M Y') }}</div>
                                <div class="cell-sub">{{ $transaction->created_at->format('H:i') }}</div>
                            </td>
                            <td>@include('partials.status-badge', ['transaction' => $transaction, 'showReview' => true])</td>
                            <td class="num">
                                <span class="{{ $transaction->isDeposit() ? 'amount-in' : 'amount-out' }}">{{ Amount::format($transaction->amount) }}</span>
                                <div class="cell-sub">{{ $transaction->currency->value }}</div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('partials.pagination', ['paginator' => $transactions])
        @endif
    </div>
@endsection
