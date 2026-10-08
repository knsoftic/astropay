@extends('layouts.app')
@use('App\Services\AstroPay\Support\Amount')

@section('title', 'Withdraw')
@section('container_class', 'narrow')

@section('content')
    <h1>Withdraw</h1>

    @if ($currencies === [])
        <div class="alert alert-warning">Withdrawals are not available right now.</div>
    @else
        <div class="card">
            <form method="POST" action="{{ route('withdrawals.store') }}" id="withdraw-form">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                <div class="field">
                    <label for="currency">Currency</label>
                    <select id="currency" name="currency" required>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->value }}" @selected(old('currency', $currencies[0]->value) === $currency->value)>
                                {{ $currency->label() }} — balance {{ Amount::format($balances[$currency->value]) }}
                            </option>
                        @endforeach
                    </select>
                    @error('currency')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <div class="field" id="method-field">
                    <label for="payment_method">Receive via</label>
                    <select id="payment_method" name="payment_method" data-old="{{ old('payment_method') }}"></select>
                    @error('payment_method')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="amount">Amount</label>
                    <input id="amount" type="text" name="amount" inputmode="decimal" value="{{ old('amount') }}" required maxlength="15" placeholder="0.00" autocomplete="off">
                    <div class="hint" id="amount-hint"></div>
                    @error('amount')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="account" id="account-label">Account</label>
                    <input id="account" type="text" name="account" value="{{ old('account') }}" required maxlength="255" autocomplete="off">
                    <div class="hint" id="account-hint"></div>
                    @error('account')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <div class="field" id="bank-code-field">
                    <label for="bank_code">IFSC code</label>
                    <input id="bank_code" type="text" name="bank_code" value="{{ old('bank_code') }}" maxlength="11" autocomplete="off" style="text-transform:uppercase">
                    <div class="hint">Required only when paying to a bank account number, e.g. HDFC0000123. Leave empty for a UPI ID.</div>
                    @error('bank_code')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <div class="field" id="account-phone-field">
                    <label for="account_phone">Mobile number on the account</label>
                    <input id="account_phone" type="text" name="account_phone" inputmode="tel" value="{{ old('account_phone') }}" maxlength="20" autocomplete="tel">
                    <div class="hint">10-digit Indian mobile number, e.g. 9876543210.</div>
                    @error('account_phone')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="person_name" id="person-name-label">Account holder name</label>
                    <input id="person_name" type="text" name="person_name" value="{{ old('person_name', auth()->user()->name) }}" maxlength="100" autocomplete="name">
                    @error('person_name')<div class="error-text">{{ $message }}</div>@enderror
                </div>

                <p class="muted">
                    The amount is deducted from your wallet now.
                    @if ($requiresApproval)
                        It is sent after review by our team.
                    @endif
                    If the withdrawal fails or is rejected, the full amount is returned to your wallet.
                </p>

                <button type="submit" class="btn" id="withdraw-submit">Request withdrawal</button>
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
                const accountCopy = {
                    INR: ['UPI ID or bank account number', 'e.g. name@okhdfcbank or 50100012345678'],
                    PKR: ['Wallet mobile number', 'The Easypaisa/JazzCash number, e.g. 03001234567'],
                    BDT: ['Wallet mobile number', 'The bKash/Nagad number, e.g. 01712345678'],
                    USDT: ['USDT TRC20 address', '34 characters starting with T. Double-check it: crypto transfers cannot be reversed.']
                };
                const currency = document.getElementById('currency');
                const method = document.getElementById('payment_method');
                let oldMethod = method.dataset.old || '';

                function toggle(id, show) { document.getElementById(id).classList.toggle('hidden', !show); }

                function refresh() {
                    const code = currency.value;
                    const options = methods[code] || [];
                    method.innerHTML = '';
                    options.forEach(function (o) { method.add(new Option(o.label, o.value, false, o.value === oldMethod)); });
                    oldMethod = '';
                    toggle('method-field', options.length > 0);
                    method.required = options.length > 0;

                    toggle('bank-code-field', code === 'INR');
                    toggle('account-phone-field', code === 'INR');
                    document.getElementById('account_phone').required = code === 'INR';

                    const copy = accountCopy[code] || ['Account', ''];
                    document.getElementById('account-label').textContent = copy[0];
                    document.getElementById('account-hint').textContent = copy[1];
                    document.getElementById('person-name-label').textContent = code === 'USDT' ? 'Beneficiary name (optional)' : 'Account holder name';
                    document.getElementById('person_name').required = code !== 'USDT';

                    const l = limits[code] || {};
                    const parts = [];
                    if (l.min) parts.push('Minimum ' + l.min + ' ' + code);
                    if (l.max) parts.push('maximum ' + l.max + ' ' + code);
                    document.getElementById('amount-hint').textContent = parts.join(', ');
                }

                currency.addEventListener('change', refresh);
                refresh();

                document.getElementById('withdraw-form').addEventListener('submit', function () {
                    document.getElementById('withdraw-submit').disabled = true;
                });
            })();
        </script>
    @endpush
@endif
