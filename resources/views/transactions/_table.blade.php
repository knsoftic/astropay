@use('App\Services\AstroPay\Support\Amount')
@if ($transactions->isEmpty())
    <p class="muted">No transactions yet.</p>
@else
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Method</th>
                <th class="num">Amount</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $transaction->type->label() }}</td>
                    <td>{{ $transaction->payment_method?->label() ?? ($transaction->currency->value === 'USDT' ? 'USDT (TRC20)' : 'Auto') }}</td>
                    <td class="num">{{ Amount::format($transaction->amount) }} {{ $transaction->currency->value }}</td>
                    <td>@include('partials.status-badge', ['transaction' => $transaction])</td>
                    <td><a href="{{ route('transactions.show', $transaction) }}">Details</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
