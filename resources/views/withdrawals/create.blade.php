@extends('layouts.app')
@use('App\Services\AstroPay\Support\Amount')

@section('title', 'Withdraw')
@section('subtitle', 'Send money from your wallet to your own account')

@section('content')
    @if ($currencies === [])
        <div class="card">
            <x-empty icon="wallet" title="Withdrawals are paused" text="No payment currency is available right now. Please try again later.">
                <a class="btn btn-secondary" href="{{ route('dashboard') }}"><x-icon name="arrow-left" /> Back to dashboard</a>
            </x-empty>
        </div>
    @else
        @php($current = old('currency', $selected))
        <form method="POST" action="{{ route('withdrawals.store') }}" id="withdraw-form" data-lock>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

            <div class="layout-side">
                <div class="stack">
                    <div class="card">
                        <div class="card-title" style="margin-bottom:16px"><x-icon name="wallet" /> 1. Withdraw from</div>
                        <div class="tiles" role="radiogroup" aria-label="Currency">
                            @foreach ($currencies as $currency)
                                <label class="tile" title="{{ $currency->label() }}">
                                    <input type="radio" name="currency" value="{{ $currency->value }}" @checked($current === $currency->value) required>
                                    <span class="tile-body">
                                        <x-coin :currency="$currency" size="sm" />
                                        <span>
                                            <span class="tile-title">{{ $currency->value }} wallet</span>
                                            <span class="tile-sub" style="display:block">Balance {{ Amount::format($balances[$currency->value]) }}</span>
                                        </span>
                                        <span class="check"><x-icon name="check" /></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('currency')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                    </div>

                    <div class="card" id="method-card">
                        <div class="card-title" style="margin-bottom:14px"><x-icon name="bank" /> 2. Receive via</div>
                        <div class="chips" id="method-chips" data-old="{{ old('payment_method') }}" role="radiogroup" aria-label="Receive via"></div>
                        @error('payment_method')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                    </div>

                    <div class="card">
                        <div class="card-title" style="margin-bottom:16px"><x-icon name="withdraw" /> <span><span id="step-details">3</span>. Amount and beneficiary</span></div>

                        <div class="field">
                            <div class="label-row">
                                <label class="label" for="amount">Amount</label>
                                <span class="small muted">Available: <strong id="available" class="soft"></strong></span>
                            </div>
                            <div class="affix has-suffix">
                                <span class="prefix" id="amount-prefix">{{ $current }}</span>
                                <input id="amount" class="input-lg" type="text" name="amount" inputmode="decimal" value="{{ old('amount') }}" required maxlength="15" placeholder="0.00" autocomplete="off" @class(['is-invalid' => $errors->has('amount')])>
                                <span class="suffix"><button type="button" class="affix-btn" id="max-btn">MAX</button></span>
                            </div>
                            <div class="hint" id="amount-hint"></div>
                            @error('amount')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                        </div>

                        <div class="field">
                            <label for="account" id="account-label">Account</label>
                            <input id="account" type="text" name="account" value="{{ old('account') }}" required maxlength="255" autocomplete="off" spellcheck="false" @class(['is-invalid' => $errors->has('account')])>
                            <div class="hint" id="account-hint"></div>
                            @error('account')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                        </div>

                        <div class="grid-2" style="gap:16px">
                            <div class="field" id="bank-code-field">
                                <label for="bank_code">IFSC code</label>
                                <input id="bank_code" type="text" name="bank_code" value="{{ old('bank_code') }}" maxlength="11" autocomplete="off" placeholder="HDFC0000123" style="text-transform:uppercase" @class(['is-invalid' => $errors->has('bank_code')])>
                                <div class="hint">Only for a bank account number. Leave empty for a UPI ID.</div>
                                @error('bank_code')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                            </div>
                            <div class="field" id="account-phone-field">
                                <label for="account_phone">Mobile number on the account</label>
                                <input id="account_phone" type="text" name="account_phone" inputmode="tel" value="{{ old('account_phone') }}" maxlength="20" autocomplete="tel" placeholder="9876543210" @class(['is-invalid' => $errors->has('account_phone')])>
                                @error('account_phone')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="field mb-0">
                            <label for="person_name" id="person-name-label">Account holder name</label>
                            <input id="person_name" type="text" name="person_name" value="{{ old('person_name', auth()->user()->name) }}" maxlength="100" autocomplete="name" @class(['is-invalid' => $errors->has('person_name')])>
                            @error('person_name')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <aside class="sticky">
                    <div class="card">
                        <div class="card-title" style="margin-bottom:14px"><x-icon name="file" /> Summary</div>
                        <div class="summary">
                            <div class="summary-row"><span>From</span><strong id="sum-currency">{{ $current }} wallet</strong></div>
                            <div class="summary-row"><span>Via</span><strong id="sum-method">—</strong></div>
                            <div class="summary-row"><span>Balance after</span><strong id="sum-after">—</strong></div>
                            <div class="summary-row summary-total"><span>You receive</span><strong id="sum-amount">—</strong></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg btn-block mt-16">Request withdrawal <x-icon name="arrow-right" /></button>
                        <div class="trust">
                            <div><x-icon name="wallet" /> The amount is held from your wallet now.</div>
                            @if ($requiresApproval)
                                <div><x-icon name="shield" /> Our team reviews the request before it is sent.</div>
                            @endif
                            <div><x-icon name="refresh" /> If it fails or is rejected, the full amount returns to your wallet.</div>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    @endif
@endsection

@if ($currencies !== [])
    @push('scripts')
        <script>
            (function () {
                var methods = @json($methods);
                var limits = @json($limits);
                var balances = @json($balances);
                var copy = {
                    INR: ['UPI ID or bank account number', 'e.g. name@okhdfcbank or 50100012345678'],
                    PKR: ['Wallet mobile number', 'Your Easypaisa / JazzCash number, e.g. 03001234567'],
                    BDT: ['Wallet mobile number', 'Your bKash / Nagad number, e.g. 01712345678'],
                    USDT: ['USDT TRC20 address', '34 characters starting with T. Double-check it: crypto transfers cannot be reversed.']
                };
                var form = document.getElementById('withdraw-form');
                var chips = document.getElementById('method-chips');
                var amountInput = document.getElementById('amount');
                var oldMethod = chips.dataset.old || '';

                function currency() {
                    var checked = form.querySelector('input[name=currency]:checked');
                    return checked ? checked.value : '';
                }
                function toFixed2(value) {
                    var parts = String(value || '0').split('.');
                    return parts[0] + '.' + ((parts[1] || '') + '00').slice(0, 2);
                }
                function money(value) {
                    return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                function toggle(id, show) { document.getElementById(id).classList.toggle('hidden', !show); }

                function chip(value, label) {
                    var wrap = document.createElement('label');
                    wrap.className = 'chip';
                    var input = document.createElement('input');
                    input.type = 'radio';
                    input.name = 'payment_method';
                    input.value = value;
                    input.required = true;
                    input.checked = value === oldMethod;
                    var span = document.createElement('span');
                    var dot = document.createElement('i');
                    dot.className = 'dot m-' + value;
                    span.appendChild(dot);
                    span.appendChild(document.createTextNode(label));
                    wrap.appendChild(input);
                    wrap.appendChild(span);
                    return wrap;
                }

                function refreshSummary() {
                    var code = currency();
                    var amount = amountInput.value.trim();
                    var valid = /^\d+(\.\d{1,2})?$/.test(amount);
                    var method = form.querySelector('input[name=payment_method]:checked');
                    var balance = Number(toFixed2(balances[code]));
                    document.getElementById('sum-currency').textContent = code + ' wallet';
                    document.getElementById('sum-method').textContent = method ? method.parentNode.textContent.trim() : (code === 'USDT' ? 'USDT (TRC20)' : '—');
                    document.getElementById('sum-amount').textContent = valid ? money(amount) + ' ' + code : '—';
                    var after = document.getElementById('sum-after');
                    after.textContent = valid ? money(balance - Number(amount)) + ' ' + code : money(balance) + ' ' + code;
                    after.style.color = valid && Number(amount) > balance ? 'var(--danger)' : '';
                }

                function refresh() {
                    var code = currency();
                    var options = methods[code] || [];
                    chips.innerHTML = '';
                    options.forEach(function (o) { chips.appendChild(chip(o.value, o.label)); });
                    if (options.length > 0 && !chips.querySelector('input:checked')) {
                        chips.querySelector('input').checked = true;
                    }
                    oldMethod = '';
                    toggle('method-card', options.length > 0);
                    document.getElementById('step-details').textContent = options.length > 0 ? '3' : '2';

                    toggle('bank-code-field', code === 'INR');
                    toggle('account-phone-field', code === 'INR');
                    document.getElementById('account_phone').required = code === 'INR';
                    var c = copy[code] || ['Account', ''];
                    document.getElementById('account-label').textContent = c[0];
                    document.getElementById('account-hint').textContent = c[1];
                    document.getElementById('person-name-label').textContent = code === 'USDT' ? 'Beneficiary name (optional)' : 'Account holder name';
                    document.getElementById('person_name').required = code !== 'USDT';

                    var l = limits[code] || {};
                    var parts = [];
                    if (l.min) parts.push('Minimum ' + l.min + ' ' + code);
                    if (l.max) parts.push('maximum ' + l.max + ' ' + code);
                    document.getElementById('amount-hint').textContent = parts.join(' · ');
                    document.getElementById('amount-prefix').textContent = code;
                    document.getElementById('available').textContent = money(toFixed2(balances[code])) + ' ' + code;
                    refreshSummary();
                }

                document.getElementById('max-btn').addEventListener('click', function () {
                    amountInput.value = toFixed2(balances[currency()]);
                    refreshSummary();
                });
                form.addEventListener('change', function (event) {
                    if (event.target.name === 'currency') { refresh(); } else { refreshSummary(); }
                });
                amountInput.addEventListener('input', refreshSummary);
                refresh();
            })();
        </script>
    @endpush
@endif
