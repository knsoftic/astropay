@extends('layouts.app')
@use('App\Enums\AstroPay\TransactionStatus')
@use('App\Services\AstroPay\Support\Amount')
@use('App\Services\AstroPay\TransactionProcessor')

@section('title', $transaction->type->label().' '.$transaction->order_id)
@section('subtitle', ($transaction->user ? $transaction->user->name.' · ' : '').$transaction->created_at->format('d M Y, H:i'))

@section('actions')
    <a class="btn btn-secondary" href="{{ route('admin.transactions.index') }}"><x-icon name="arrow-left" /> <span>All transactions</span></a>
@endsection

@section('content')
    @if ($transaction->needs_review)
        <div class="alert alert-warning">
            <x-icon name="alert" />
            <div class="body"><strong>Needs review.</strong><div style="white-space:pre-line;margin-top:4px">{{ $transaction->review_reason }}</div></div>
        </div>
    @endif

    @if ($transaction->error_message)
        <div class="alert alert-error">
            <x-icon name="x-circle" />
            <div class="body">Last error{{ $transaction->error_code !== null ? ' ('.$transaction->error_code.')' : '' }}: {{ $transaction->error_message }}</div>
        </div>
    @endif

    <div class="layout-side">
        <div class="stack">
            <div class="card">
                <div class="tx-hero">
                    <span class="tx-icon {{ $transaction->isDeposit() ? 'tx-in' : 'tx-out' }}"><x-icon :name="$transaction->isDeposit() ? 'deposit' : 'withdraw'" /></span>
                    <div style="flex:1;min-width:0">
                        <div class="muted small">{{ $transaction->type->label() }} · {{ $transaction->currency->label() }}</div>
                        <div class="big">{{ Amount::format($transaction->amount) }}<small>{{ $transaction->currency->value }}</small></div>
                    </div>
                    <div class="row wrap" style="gap:6px">@include('partials.status-badge', ['transaction' => $transaction, 'showReview' => true])</div>
                </div>
                @include('partials.timeline', ['transaction' => $transaction])
            </div>

            <div class="card">
                <div class="card-title" style="margin-bottom:8px"><x-icon name="file" /> Details</div>
                <dl class="dl">
                    <dt>Order ID</dt>
                    <dd><span class="mono">{{ $transaction->order_id }}</span> <button type="button" class="link-btn small" data-copy="{{ $transaction->order_id }}" style="margin-left:8px"><span data-copy-label>Copy</span></button></dd>
                    <dt>User</dt><dd>{{ $transaction->user ? $transaction->user->name.' <'.$transaction->user->email.'>' : '—' }}</dd>
                    <dt>Currency / method</dt><dd>{{ $transaction->currency->value }} / {{ $transaction->payment_method?->value ?? ($transaction->currency->usesPaymentMethods() ? 'automatic' : '—') }}</dd>
                    <dt>Gateway status</dt><dd>{{ $transaction->gateway_status ?? '—' }}</dd>
                    <dt>Requested amount</dt><dd>{{ Amount::format($transaction->amount, 4) }}</dd>
                    <dt>Settled amount</dt><dd>{{ $transaction->settled_amount !== null ? Amount::format($transaction->settled_amount, 4) : '—' }}</dd>
                    <dt>Commission</dt><dd>{{ $transaction->commission !== null ? Amount::format($transaction->commission, 4) : '—' }}</dd>
                    @if ($transaction->isPayout())
                        <dt>Net debited by AstroPay</dt><dd>{{ $transaction->net_amount !== null ? Amount::format($transaction->net_amount, 4) : '—' }}</dd>
                        <dt>Beneficiary</dt>
                        <dd>
                            {{ $transaction->beneficiary_name ?? '—' }}
                            <div class="mono">{{ $transaction->beneficiary_account }}</div>
                            <div class="muted small">{{ collect([
                                $transaction->beneficiary_bank_code ? 'IFSC '.$transaction->beneficiary_bank_code : null,
                                $transaction->beneficiary_phone ? 'Phone '.$transaction->beneficiary_phone : null,
                            ])->filter()->implode(' · ') }}</div>
                        </dd>
                        <dt>Attempts</dt><dd>{{ $transaction->attempts }}</dd>
                        <dt>Approved</dt><dd>{{ $transaction->approver ? $transaction->approver->email.' at '.$transaction->approved_at?->format('Y-m-d H:i:s') : '—' }}</dd>
                        @if ($transaction->rejecter)
                            <dt>Rejected</dt><dd>{{ $transaction->rejecter->email }} at {{ $transaction->rejected_at?->format('Y-m-d H:i:s') }}</dd>
                        @endif
                        <dt>Remark</dt><dd>{{ $transaction->remark ?? '—' }}</dd>
                    @else
                        <dt>Customer</dt><dd>{{ $transaction->customer_name }}<div class="muted small">{{ $transaction->customer_phone }} · {{ $transaction->customer_email }}</div></dd>
                        <dt>Supplemented UTR</dt><dd class="mono">{{ $transaction->supplemented_utr ?? '—' }}</dd>
                    @endif
                    <dt>UTR</dt><dd class="mono">{{ $transaction->utr ?? '—' }}</dd>
                    <dt>Test order</dt><dd>{{ $transaction->is_test ? 'Yes' : 'No' }}</dd>
                    <dt>AstroPay times</dt><dd>{{ $transaction->gateway_create_time ?? '—' }} → {{ $transaction->gateway_update_time ?? '—' }}</dd>
                    <dt>Sent to AstroPay</dt><dd>{{ $transaction->submitted_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                    <dt>Last callback</dt><dd>{{ $transaction->callback_received_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                    <dt>Last status check</dt><dd>{{ $transaction->last_checked_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                    <dt>Completed</dt><dd>{{ $transaction->completed_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                    @if ($transaction->reviewer)
                        <dt>Reviewed</dt><dd>{{ $transaction->reviewer->email }} at {{ $transaction->reviewed_at?->format('Y-m-d H:i:s') }}<div class="muted small">{{ $transaction->review_note }}</div></dd>
                    @endif
                </dl>
            </div>

            @if ($transaction->last_callback)
                <div class="card">
                    <div class="card-title" style="margin-bottom:12px"><x-icon name="activity" /> Last callback payload</div>
                    <pre>{{ json_encode($transaction->last_callback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            @endif

            <div class="card card-flush">
                <div class="card-head"><div class="card-title"><x-icon name="wallet" /> Wallet movements</div></div>
                @if ($entries->isEmpty())
                    <p class="muted" style="padding:0 22px 20px">No wallet movements for this transaction.</p>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="num">Amount</th><th class="num">Balance after</th></tr></thead>
                            <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td class="nowrap">{{ $entry->created_at->format('Y-m-d H:i:s') }}</td>
                                    <td>{{ $entry->type->label() }}</td>
                                    <td>{{ $entry->description }}</td>
                                    <td class="num"><span class="{{ str_starts_with((string) $entry->amount, '-') ? 'amount-out' : 'amount-in' }}">{{ Amount::format($entry->amount, 4) }}</span></td>
                                    <td class="num">{{ Amount::format($entry->balance_after, 4) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card card-flush">
                <div class="card-head"><div class="card-title"><x-icon name="link" /> Callbacks received</div></div>
                @if ($webhooks->isEmpty())
                    <p class="muted" style="padding:0 22px 20px">No callbacks received yet.</p>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Received</th><th>IP</th><th>Signature</th><th>Outcome</th><th>Message</th></tr></thead>
                            <tbody>
                            @foreach ($webhooks as $log)
                                <tr>
                                    <td class="nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                                    <td class="mono">{{ $log->ip }}</td>
                                    <td>@if ($log->signature_valid)<span class="badge badge-success">valid</span>@else<span class="badge badge-danger">invalid</span>@endif</td>
                                    <td><span @class(['badge', 'no-dot', 'badge-success' => $log->http_status < 300, 'badge-danger' => $log->http_status >= 300])>{{ $log->outcome }} · {{ $log->http_status }}</span></td>
                                    <td class="small">{{ $log->message }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <aside class="sticky stack">
            @if ($transaction->isPayout() && $transaction->status === TransactionStatus::AwaitingApproval)
                <div class="card" style="border-color:var(--primary-100);box-shadow:0 0 0 4px var(--ring)">
                    <div class="card-title" style="margin-bottom:12px"><x-icon name="check-circle" /> Approve withdrawal</div>
                    <div class="summary" style="margin-bottom:14px">
                        <div class="summary-row"><span>Amount</span><strong>{{ Amount::format($transaction->amount) }} {{ $transaction->currency->value }}</strong></div>
                        <div class="summary-row"><span>To</span><strong>{{ $transaction->beneficiary_name ?? '—' }}</strong></div>
                        <div class="summary-row"><span>Via</span><strong>{{ $transaction->payment_method?->label() ?? 'USDT (TRC20)' }}</strong></div>
                        <div class="summary-row"><span>Account</span><strong class="mono break">{{ $transaction->beneficiary_account }}</strong></div>
                        @if ($transaction->beneficiary_bank_code)
                            <div class="summary-row"><span>IFSC</span><strong class="mono">{{ $transaction->beneficiary_bank_code }}</strong></div>
                        @endif
                        @if ($transaction->beneficiary_phone)
                            <div class="summary-row"><span>Phone</span><strong class="mono">{{ $transaction->beneficiary_phone }}</strong></div>
                        @endif
                    </div>
                    <p class="muted small">AstroPay adds its commission on top and deducts the total from the merchant balance.</p>
                    <form method="POST" action="{{ route('admin.transactions.approve', $transaction) }}" data-confirm="Send this payout to AstroPay now?" data-lock>
                        @csrf
                        <button type="submit" class="btn btn-primary btn-block"><x-icon name="check" /> Approve and send</button>
                    </form>
                    <div class="divider-text">or</div>
                    <form method="POST" action="{{ route('admin.transactions.reject', $transaction) }}" data-confirm="Reject this withdrawal and refund the user?" data-lock>
                        @csrf
                        <div class="field">
                            <label for="reason">Reject with reason</label>
                            <input id="reason" type="text" name="reason" required minlength="3" maxlength="500" placeholder="Shown to the user">
                        </div>
                        <button type="submit" class="btn btn-soft-danger btn-block"><x-icon name="x" /> Reject and refund</button>
                    </form>
                </div>
            @endif

            @if ($transaction->needs_review)
                <div class="card" style="border-color:var(--warning-border)">
                    <div class="card-title" style="margin-bottom:12px"><x-icon name="alert" /> Resolve review</div>
                    <form method="POST" action="{{ route('admin.transactions.resolve', $transaction) }}" data-confirm="Apply this resolution?" data-lock>
                        @csrf
                        <div class="field">
                            <label for="action">Action</label>
                            <select id="action" name="action" required>
                                <option value="{{ TransactionProcessor::REVIEW_ACTION_DISMISS }}">Dismiss: no balance change</option>
                                @if ($transaction->isDeposit())
                                    <option value="{{ TransactionProcessor::REVIEW_ACTION_CREDIT }}">Credit the user's wallet</option>
                                @else
                                    <option value="{{ TransactionProcessor::REVIEW_ACTION_RECLAIM }}">Reverse the refund (AstroPay paid it)</option>
                                @endif
                            </select>
                            <div class="hint">Run “Check status” first so the decision uses AstroPay's latest answer.</div>
                        </div>
                        <div class="field">
                            <label for="note">Note</label>
                            <textarea id="note" name="note" rows="3" required minlength="3" maxlength="1000" placeholder="Why this decision?"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Resolve</button>
                    </form>
                </div>
            @endif

            <div class="card">
                <div class="card-title" style="margin-bottom:12px"><x-icon name="refresh" /> Status</div>
                @if ($transaction->canCheckStatus())
                    <form method="POST" action="{{ route('admin.transactions.check', $transaction) }}" data-lock>
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-block"><x-icon name="refresh" /> Check status with AstroPay</button>
                    </form>
                    <p class="muted small mt-8 mb-0">Queries AstroPay and applies the result. Safe to repeat.</p>
                @else
                    <p class="muted small mb-0">Not sent to AstroPay yet, so there is nothing to check.</p>
                @endif
            </div>
        </aside>
    </div>
@endsection
