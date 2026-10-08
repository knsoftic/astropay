@extends('layouts.app')

@section('title', 'Admin overview')
@section('subtitle', 'Payments health at a glance')

@section('actions')
    <a class="btn btn-secondary" href="{{ route('admin.settings.edit') }}"><x-icon name="settings" /> <span>Settings</span></a>
    <a class="btn btn-primary" href="{{ route('admin.transactions.index', ['type' => 'payout', 'status' => 'awaiting_approval']) }}"><x-icon name="check-circle" /> <span>Review approvals</span></a>
@endsection

@section('content')
    <div class="stack">
        <div class="grid-kpi">
            <a class="kpi" href="{{ route('admin.transactions.index', ['type' => 'payout', 'status' => 'awaiting_approval']) }}">
                <span class="kpi-icon tone-violet"><x-icon name="check-circle" /></span>
                <span><span class="kpi-value" style="display:block">{{ $awaitingApproval }}</span><span class="kpi-label">Withdrawals awaiting approval</span></span>
            </a>
            <a class="kpi" href="{{ route('admin.transactions.index', ['review' => 1]) }}">
                <span class="kpi-icon {{ $needsReview > 0 ? 'tone-danger' : 'tone-success' }}"><x-icon name="alert" /></span>
                <span><span class="kpi-value" style="display:block">{{ $needsReview }}</span><span class="kpi-label">Need review</span></span>
            </a>
            <a class="kpi" href="{{ route('admin.transactions.index', ['status' => 'unknown']) }}">
                <span class="kpi-icon tone-warning"><x-icon name="clock" /></span>
                <span><span class="kpi-value" style="display:block">{{ $unknown }}</span><span class="kpi-label">Unconfirmed (check status)</span></span>
            </a>
            <a class="kpi" href="{{ route('admin.transactions.index', ['status' => 'pending']) }}">
                <span class="kpi-icon tone-info"><x-icon name="refresh" /></span>
                <span><span class="kpi-value" style="display:block">{{ $pending }}</span><span class="kpi-label">Pending at AstroPay</span></span>
            </a>
            <a class="kpi" href="{{ route('admin.webhooks.index', ['failed' => 1]) }}">
                <span class="kpi-icon {{ $failedWebhooks > 0 ? 'tone-danger' : 'tone-primary' }}"><x-icon name="activity" /></span>
                <span><span class="kpi-value" style="display:block">{{ $failedWebhooks }}</span><span class="kpi-label">Rejected callbacks (24h)</span></span>
            </a>
        </div>

        <div class="layout-side">
            <div class="card card-flush">
                <div class="card-head">
                    <div>
                        <div class="card-title"><x-icon name="bank" /> Merchant accounts</div>
                        <div class="card-desc">One AstroPay merchant account per currency</div>
                    </div>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.settings.edit') }}">Manage <x-icon name="chevron-right" /></a>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Currency</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                        <tbody>
                        @foreach ($currencies as $currency)
                            <tr>
                                <td>
                                    <div class="row">
                                        <x-coin :currency="$currency" size="sm" />
                                        <div>
                                            <div class="cell-main">{{ $currency->value }}</div>
                                            <div class="cell-sub">{{ $currency->label() }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @if (in_array($currency, $enabled, true))
                                        <span class="badge badge-success">Enabled</span>
                                    @elseif (in_array($currency, $configured, true))
                                        <span class="badge badge-warning">Disabled</span>
                                    @else
                                        <span class="badge badge-muted">Not configured</span>
                                    @endif
                                </td>
                                <td class="text-right nowrap">
                                    @if (in_array($currency, $configured, true))
                                        <a class="btn btn-secondary btn-sm" href="{{ route('admin.balance', $currency->value) }}"><x-icon name="wallet" /> Balance</a>
                                    @endif
                                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.settings.edit') }}#{{ strtolower($currency->value) }}"><x-icon name="settings" /> Settings</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <aside class="stack">
                <div class="card">
                    <div class="card-title" style="margin-bottom:12px"><x-icon name="shield" /> Configuration</div>
                    <ul class="list-plain">
                        <li><span class="soft">Withdrawal approval</span>
                            @if ($requiresApproval)
                                <span class="badge badge-success">Required</span>
                            @else
                                <span class="badge badge-warning">Off</span>
                            @endif
                        </li>
                        <li style="display:block">
                            <div class="soft" style="margin-bottom:6px">Callback URL example</div>
                            <div class="copy-field">
                                <code>{{ $callbackPreview }}</code>
                                <button type="button" class="btn btn-ghost btn-sm" data-copy="{{ $callbackPreview }}"><x-icon name="copy" /> <span data-copy-label>Copy</span></button>
                            </div>
                        </li>
                    </ul>
                </div>
            </aside>
        </div>

        @isset($balanceCurrency)
            <div class="card" id="balance">
                <div class="card-head">
                    <div class="card-title"><x-coin :currency="$balanceCurrency" size="sm" /> {{ $balanceCurrency->value }} merchant balance</div>
                    <a class="btn btn-secondary btn-sm" href="{{ route('admin.balance', $balanceCurrency->value) }}"><x-icon name="refresh" /> Refresh</a>
                </div>
                @if ($balanceError)
                    <div class="alert alert-error mb-0"><x-icon name="x-circle" /><div class="body">{{ $balanceError }}</div></div>
                @else
                    @php($highlight = ['Balance' => 'Available now', 'FreezeBalance' => 'Held for pending withdrawals', 'TodayCollectionAmount' => 'Deposits today', 'TodayPaymentAmount' => 'Withdrawals today'])
                    <div class="grid-kpi" style="margin-bottom:18px">
                        @foreach ($highlight as $key => $label)
                            @if (array_key_exists($key, $balance) && is_scalar($balance[$key]))
                                <div class="kpi" style="box-shadow:none">
                                    <span><span class="kpi-value" style="display:block;font-size:20px">{{ $balance[$key] }}</span><span class="kpi-label">{{ $label }}</span></span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    <dl class="dl">
                        @foreach ($balance as $key => $value)
                            <dt>{{ $key }}</dt>
                            <dd class="mono">{{ is_scalar($value) || $value === null ? $value : json_encode($value) }}</dd>
                        @endforeach
                    </dl>
                @endif
            </div>
        @endisset
    </div>
@endsection
