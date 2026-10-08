@extends('layouts.app')

@section('title', 'Deposit')
@section('container_class', 'narrow')

@section('content')
    <h1>Deposit</h1>

    @if ($currencies === [])
        <div class="alert alert-warning">Deposits are not available right now.</div>
    @else
        <div class="card">
            <form method="POST" action="{{ route('deposits.store') }}" id="deposit-form">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                <div class="field">
                    <label for="currency">Currency</label>
                    <select id="currency" name="currency" required>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->value }}" @selected(old('currency', $currencies[0]->value) === $currency->value)>{{ $currency->label() }}</option>
                        @endforeach
                    </select>
                    @error('currency')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <div class="field" id="method-field">
                    <label for="payment_method">Payment method</label>
                    <select id="payment_method" name="payment_method" data-old="{{ old('payment_method') }}"></select>
                    <div class="hint">“Automatic” lets the payment provider choose.</div>
                    @error('payment_method')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="amount">Amount</label>
                    <input id="amount" type="text" name="amount" inputmode="decimal" value="{{ old('amount') }}" required maxlength="15" placeholder="0.00" autocomplete="off">
                    <div class="hint" id="amount-hint"></div>
                    @error('amount')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="phone">Mobile number</label>
                    <input id="phone" type="text" name="phone" inputmode="tel" value="{{ old('phone', $lastPhone) }}" required maxlength="20" autocomplete="tel">
                    <div class="hint" id="phone-hint"></div>
                    @error('phone')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <p class="muted">You will be redirected to the secure payment page to complete the payment. Your wallet is credited once the payment is confirmed.</p>

                <button type="submit" class="btn" id="deposit-submit">Continue to payment</button>
            </form>
        </div>
    @endif
@endsection

@if ($currencies !== [])
    @push('scripts')
        <script>
            (function () {
                const methods = @json($methods);
                const limits = @json($limits);
                const phoneExamples = { INR: '9876543210', PKR: '03001234567', BDT: '01712345678', USDT: '+1 555 123 4567' };
                const currency = document.getElementById('currency');
                const method = document.getElementById('payment_method');
                const methodField = document.getElementById('method-field');
                const amountHint = document.getElementById('amount-hint');
                const phoneHint = document.getElementById('phone-hint');
                let oldMethod = method.dataset.old || '';

                function refresh() {
                    const code = currency.value;
                    const options = methods[code] || [];
                    method.innerHTML = '';

                    if (options.length === 0) {
                        methodField.classList.add('hidden');
                    } else {
                        methodField.classList.remove('hidden');
                        method.add(new Option('Automatic', ''));
                        options.forEach(function (o) { method.add(new Option(o.label, o.value, false, o.value === oldMethod)); });
                    }
                    oldMethod = '';

                    const l = limits[code] || {};
                    const parts = [];
                    if (l.min) parts.push('Minimum ' + l.min + ' ' + code);
                    if (l.max) parts.push('maximum ' + l.max + ' ' + code);
                    amountHint.textContent = parts.join(', ');
                    phoneHint.textContent = 'Example: ' + (phoneExamples[code] || '');
                }

                currency.addEventListener('change', refresh);
                refresh();

                document.getElementById('deposit-form').addEventListener('submit', function () {
                    document.getElementById('deposit-submit').disabled = true;
                });
            })();
        </script>
    @endpush
@endif
