@extends('layouts.app')
@use('App\Enums\AstroPay\Currency')
@use('App\Enums\AstroPay\TransactionStatus')
@use('App\Enums\AstroPay\TransactionType')
@use('App\Services\AstroPay\Support\Amount')

@section('title', 'Admin · Transactions')

@section('content')
    <h1>Transactions</h1>
    @include('admin._nav')

    <form method="GET" action="{{ route('admin.transactions.index') }}" class="filters card">
        <div class="field">
            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="">All</option>
                @foreach (TransactionType::cases() as $type)
                    <option value="{{ $type->value }}" @selected(($filters['type'] ?? null) === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
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
        <div class="field">
            <label for="q">Order ID / UTR</label>
            <input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="64">
        </div>
        <div class="field">
            <label><input type="checkbox" name="review" value="1" @checked(request()->boolean('review'))> Needs review</label>
        </div>
        <button type="submit" class="btn">Filter</button>
    </form>

    <div class="card">
        @if ($transactions->isEmpty())
            <p class="muted">No transactions match.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Created</th>
                        <th>Order ID</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Method</th>
                        <th class="num">Amount</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($transactions as $transaction)
                        <tr>
                            <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                            <td class="mono"><a href="{{ route('admin.transactions.show', $transaction) }}">{{ $transaction->order_id }}</a></td>
                            <td>{{ $transaction->user?->email ?? '—' }}</td>
                            <td>{{ $transaction->type->label() }}</td>
                            <td>{{ $transaction->payment_method?->value ?? ($transaction->currency->usesPaymentMethods() ? 'AUTO' : 'TRC20') }}</td>
                            <td class="num">{{ Amount::format($transaction->amount) }} {{ $transaction->currency->value }}</td>
                            <td>@include('partials.status-badge', ['transaction' => $transaction, 'showReview' => true])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('partials.pagination', ['paginator' => $transactions])
        @endif
    </div>
@endsection
