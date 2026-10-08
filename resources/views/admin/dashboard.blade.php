@extends('layouts.app')

@section('title', 'Admin')

@section('content')
    <h1>Admin</h1>
    @include('admin._nav')

    <div class="grid">
        <a class="stat" href="{{ route('admin.transactions.index', ['type' => 'payout', 'status' => 'awaiting_approval']) }}">
            <div class="label">Withdrawals awaiting approval</div>
            <div class="value">{{ $awaitingApproval }}</div>
        </a>
        <a class="stat" href="{{ route('admin.transactions.index', ['review' => 1]) }}">
            <div class="label">Needs review</div>
            <div class="value">{{ $needsReview }}</div>
        </a>
        <a class="stat" href="{{ route('admin.transactions.index', ['status' => 'unknown']) }}">
            <div class="label">Unconfirmed (check status)</div>
            <div class="value">{{ $unknown }}</div>
        </a>
        <a class="stat" href="{{ route('admin.transactions.index', ['status' => 'pending']) }}">
            <div class="label">Pending at AstroPay</div>
            <div class="value">{{ $pending }}</div>
        </a>
        <a class="stat" href="{{ route('admin.webhooks.index', ['failed' => 1]) }}">
            <div class="label">Rejected callbacks (24h)</div>
            <div class="value">{{ $failedWebhooks }}</div>
        </a>
    </div>

    <div class="card">
        <h2>Merchant accounts</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Currency</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach ($currencies as $currency)
                    <tr>
                        <td>{{ $currency->label() }}</td>
                        <td>
                            @if (in_array($currency, $enabled, true))
                                <span class="badge badge-success">Enabled</span>
                            @elseif (in_array($currency, $configured, true))
                                <span class="badge badge-warning">Disabled</span>
                            @else
                                <span class="badge badge-muted">Not configured</span>
                            @endif
                        </td>
                        <td class="actions">
                            @if (in_array($currency, $configured, true))
                                <a href="{{ route('admin.balance', $currency->value) }}">Fetch balance</a>
                            @endif
                            <a href="{{ route('admin.settings.edit') }}#{{ strtolower($currency->value) }}">Settings</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted">
            Withdrawal approval: <strong>{{ $requiresApproval ? 'required' : 'off (sent immediately)' }}</strong>.
            Callback URL example: <span class="mono">{{ $callbackPreview }}</span>
        </p>
    </div>

    @isset($balanceCurrency)
        <div class="card">
            <h2>{{ $balanceCurrency->value }} balance</h2>
            @if ($balanceError)
                <div class="alert alert-error">{{ $balanceError }}</div>
            @else
                <dl class="details">
                    @foreach ($balance as $key => $value)
                        <dt>{{ $key }}</dt>
                        <dd class="mono">{{ is_scalar($value) || $value === null ? $value : json_encode($value) }}</dd>
                    @endforeach
                </dl>
                <p class="muted">Balance = available now; FreezeBalance = held for pending withdrawals. Collection/Payment figures count successful orders only.</p>
            @endif
        </div>
    @endisset
@endsection
