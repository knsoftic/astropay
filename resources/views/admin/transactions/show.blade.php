@extends('layouts.app')
@use('App\Enums\AstroPay\TransactionStatus')
@use('App\Services\AstroPay\Support\Amount')
@use('App\Services\AstroPay\TransactionProcessor')

@section('title', 'Admin · '.$transaction->order_id)

@section('content')
    <h1>{{ $transaction->type->label() }} <span class="mono">{{ $transaction->order_id }}</span></h1>
    @include('admin._nav')

    @if ($transaction->needs_review)
        <div class="alert alert-warning">
            <strong>Needs review.</strong>
            <div style="white-space:pre-line">{{ $transaction->review_reason }}</div>
        </div>
    @endif

    @if ($transaction->error_message)
        <div class="alert alert-error">
            Last error{{ $transaction->error_code !== null ? ' ('.$transaction->error_code.')' : '' }}: {{ $transaction->error_message }}
        </div>
    @endif

    <div class="actions" style="margin-bottom:20px">
        @if ($transaction->canCheckStatus())
            <form method="POST" action="{{ route('admin.transactions.check', $transaction) }}" class="inline">
                @csrf
                <button type="submit" class="btn btn-secondary">Check status with AstroPay</button>
            </form>
        @endif
    </div>

    @if ($transaction->isPayout() && $transaction->status === TransactionStatus::AwaitingApproval)
        <div class="card">
            <h2>Approve withdrawal</h2>
            <p>
                Send <strong>{{ Amount::format($transaction->amount) }} {{ $transaction->currency->value }}</strong>
                to <strong>{{ $transaction->beneficiary_name ?? '—' }}</strong>
                via {{ $transaction->payment_method?->label() ?? 'USDT (TRC20)' }}:
                <span class="mono">{{ $transaction->beneficiary_account }}</span>
                @if ($transaction->beneficiary_bank_code)
                    · IFSC <span class="mono">{{ $transaction->beneficiary_bank_code }}</span>
                @endif
                @if ($transaction->beneficiary_phone)
                    · phone <span class="mono">{{ $transaction->beneficiary_phone }}</span>
                @endif
            </p>
            <p class="muted">AstroPay adds its commission on top and deducts the total from the merchant balance.</p>
            <div class="actions">
                <form method="POST" action="{{ route('admin.transactions.approve', $transaction) }}" class="inline" onsubmit="if (!confirm('Send this payout to AstroPay now?')) { return false; } this.querySelector('button').disabled = true;">
                    @csrf
                    <button type="submit" class="btn">Approve and send</button>
                </form>
            </div>
            <form method="POST" action="{{ route('admin.transactions.reject', $transaction) }}" style="margin-top:16px">
                @csrf
                <div class="field">
                    <label for="reason">Reject (amount is refunded to the user)</label>
                    <input id="reason" type="text" name="reason" required minlength="3" maxlength="500" placeholder="Reason shown to the user">
                </div>
                <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this withdrawal and refund the user?');">Reject</button>
            </form>
        </div>
    @endif

    @if ($transaction->needs_review)
        <div class="card">
            <h2>Resolve review</h2>
            <form method="POST" action="{{ route('admin.transactions.resolve', $transaction) }}">
                @csrf
                <div class="field">
                    <label for="action">Action</label>
                    <select id="action" name="action" required>
                        <option value="{{ TransactionProcessor::REVIEW_ACTION_DISMISS }}">Dismiss — no balance change</option>
                        @if ($transaction->isDeposit())
                            <option value="{{ TransactionProcessor::REVIEW_ACTION_CREDIT }}">Credit the user's wallet with the settled amount</option>
                        @else
                            <option value="{{ TransactionProcessor::REVIEW_ACTION_RECLAIM }}">Reverse the refund (AstroPay did pay this withdrawal)</option>
                        @endif
                    </select>
                    <div class="hint">Run “Check status” first so the decision is based on AstroPay's current answer.</div>
                </div>
                <div class="field">
                    <label for="note">Note</label>
                    <textarea id="note" name="note" rows="2" required minlength="3" maxlength="1000"></textarea>
                </div>
                <button type="submit" class="btn" onclick="return confirm('Apply this resolution?');">Resolve</button>
            </form>
        </div>
    @endif

    <div class="card">
        <h2>Details</h2>
        <dl class="details">
            <dt>Status</dt><dd>@include('partials.status-badge', ['transaction' => $transaction, 'showReview' => true]) <span class="muted">gateway status: {{ $transaction->gateway_status ?? '—' }}</span></dd>
            <dt>User</dt><dd>{{ $transaction->user ? $transaction->user->name.' <'.$transaction->user->email.'>' : '—' }}</dd>
            <dt>Currency / method</dt><dd>{{ $transaction->currency->value }} / {{ $transaction->payment_method?->value ?? ($transaction->currency->usesPaymentMethods() ? 'automatic' : '—') }}</dd>
            <dt>Requested amount</dt><dd>{{ Amount::format($transaction->amount, 4) }}</dd>
            <dt>Settled amount</dt><dd>{{ $transaction->settled_amount !== null ? Amount::format($transaction->settled_amount, 4) : '—' }}</dd>
            <dt>Commission</dt><dd>{{ $transaction->commission !== null ? Amount::format($transaction->commission, 4) : '—' }}</dd>
            @if ($transaction->isPayout())
                <dt>Net debited by AstroPay</dt><dd>{{ $transaction->net_amount !== null ? Amount::format($transaction->net_amount, 4) : '—' }}</dd>
                <dt>Beneficiary</dt>
                <dd>
                    {{ $transaction->beneficiary_name ?? '—' }}<br>
                    <span class="mono">{{ $transaction->beneficiary_account }}</span>
                    @if ($transaction->beneficiary_bank_code) · IFSC {{ $transaction->beneficiary_bank_code }} @endif
                    @if ($transaction->beneficiary_phone) · {{ $transaction->beneficiary_phone }} @endif
                </dd>
                <dt>Attempts</dt><dd>{{ $transaction->attempts }}</dd>
                <dt>Approved</dt><dd>{{ $transaction->approver ? $transaction->approver->email.' at '.$transaction->approved_at?->format('Y-m-d H:i:s') : '—' }}</dd>
                @if ($transaction->rejecter)
                    <dt>Rejected</dt><dd>{{ $transaction->rejecter->email }} at {{ $transaction->rejected_at?->format('Y-m-d H:i:s') }}</dd>
                @endif
                <dt>Remark</dt><dd>{{ $transaction->remark ?? '—' }}</dd>
            @else
                <dt>Customer</dt><dd>{{ $transaction->customer_name }} · {{ $transaction->customer_phone }} · {{ $transaction->customer_email }}</dd>
                <dt>Supplemented UTR</dt><dd class="mono">{{ $transaction->supplemented_utr ?? '—' }}</dd>
            @endif
            <dt>UTR</dt><dd class="mono">{{ $transaction->utr ?? '—' }}</dd>
            <dt>Test order</dt><dd>{{ $transaction->is_test ? 'Yes' : 'No' }}</dd>
            <dt>AstroPay times</dt><dd>{{ $transaction->gateway_create_time ?? '—' }} → {{ $transaction->gateway_update_time ?? '—' }}</dd>
            <dt>Created</dt><dd>{{ $transaction->created_at->format('Y-m-d H:i:s') }}</dd>
            <dt>Sent to AstroPay</dt><dd>{{ $transaction->submitted_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
            <dt>Last callback</dt><dd>{{ $transaction->callback_received_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
            <dt>Last status check</dt><dd>{{ $transaction->last_checked_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
            <dt>Completed</dt><dd>{{ $transaction->completed_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
            @if ($transaction->reviewer)
                <dt>Reviewed</dt><dd>{{ $transaction->reviewer->email }} at {{ $transaction->reviewed_at?->format('Y-m-d H:i:s') }}: {{ $transaction->review_note }}</dd>
            @endif
        </dl>
    </div>

    @if ($transaction->last_callback)
        <div class="card">
            <h2>Last callback payload</h2>
            <pre>{{ json_encode($transaction->last_callback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

    <div class="card">
        <h2>Wallet movements</h2>
        @if ($entries->isEmpty())
            <p class="muted">None.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="num">Amount</th><th class="num">Balance after</th></tr></thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td>{{ $entry->created_at->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $entry->type->label() }}</td>
                            <td>{{ $entry->description }}</td>
                            <td class="num">{{ Amount::format($entry->amount, 4) }}</td>
                            <td class="num">{{ Amount::format($entry->balance_after, 4) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Callbacks received</h2>
        @if ($webhooks->isEmpty())
            <p class="muted">None.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Received</th><th>IP</th><th>Signature</th><th>Outcome</th><th>Message</th></tr></thead>
                    <tbody>
                    @foreach ($webhooks as $log)
                        <tr>
                            <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td class="mono">{{ $log->ip }}</td>
                            <td>{{ $log->signature_valid ? 'valid' : 'invalid' }}</td>
                            <td>{{ $log->outcome }} ({{ $log->http_status }})</td>
                            <td>{{ $log->message }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
