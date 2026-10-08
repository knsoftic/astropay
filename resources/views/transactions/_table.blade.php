@use('App\Services\AstroPay\Support\Amount')
@if ($transactions->isEmpty())
    <x-empty icon="inbox" title="No transactions yet" text="Your deposits and withdrawals will appear here.">
        <a class="btn btn-primary" href="{{ route('deposits.create') }}"><x-icon name="plus" /> Make a deposit</a>
    </x-empty>
@else
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Transaction</th>
                <th>Method</th>
                <th>Date</th>
                <th>Status</th>
                <th class="num">Amount</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($transactions as $transaction)
                <tr class="clickable" onclick="window.location='{{ route('transactions.show', $transaction) }}'">
                    <td>
                        <div class="row">
                            <span class="tx-icon {{ $transaction->isDeposit() ? 'tx-in' : 'tx-out' }}"><x-icon :name="$transaction->isDeposit() ? 'deposit' : 'withdraw'" /></span>
                            <div>
                                <a class="cell-main" href="{{ route('transactions.show', $transaction) }}" style="color:inherit">{{ $transaction->type->label() }}</a>
                                <div class="cell-sub mono">{{ $transaction->order_id }}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="row" style="gap:8px">
                            <x-coin :currency="$transaction->currency" size="sm" />
                            <span>{{ $transaction->payment_method?->label() ?? ($transaction->currency->usesPaymentMethods() ? 'Automatic' : 'USDT TRC20') }}</span>
                        </div>
                    </td>
                    <td class="nowrap">
                        <div>{{ $transaction->created_at->format('d M Y') }}</div>
                        <div class="cell-sub">{{ $transaction->created_at->format('H:i') }}</div>
                    </td>
                    <td>@include('partials.status-badge', ['transaction' => $transaction])</td>
                    <td class="num">
                        <span class="{{ $transaction->isDeposit() ? 'amount-in' : 'amount-out' }}">{{ $transaction->isDeposit() ? '+' : '−' }}{{ Amount::format($transaction->amount) }}</span>
                        <div class="cell-sub">{{ $transaction->currency->value }}</div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
