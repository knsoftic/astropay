@extends('layouts.app')

@section('title', 'Deposit')
@section('subtitle', 'Add money to your wallet')

@section('content')
    @if ($currencies === [])
        <div class="card">
            <x-empty icon="wallet" title="Deposits are paused" text="No payment currency is available right now. Please try again later.">
                <a class="btn btn-secondary" href="{{ route('dashboard') }}"><x-icon name="arrow-left" /> Back to dashboard</a>
            </x-empty>
        </div>
    @else
        @php($current = old('currency', $selected))
        <form method="POST" action="{{ route('deposits.store') }}" id="deposit-form" data-lock>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

            <div class="layout-side">
                <div class="stack">
                    <div class="card">
                        <div class="card-head mb-0" style="margin-bottom:16px">
                            <div class="card-title"><x-icon name="wallet" /> 1. Choose a currency</div>
                        </div>
                        <div class="tiles" role="radiogroup" aria-label="Currency">
                            @foreach ($currencies as $currency)
                                <label class="tile">
                                    <input type="radio" name="currency" value="{{ $currency->value }}" @checked($current === $currency->value) required>
                                    <span class="tile-body">
                                        <x-coin :currency="$currency" size="sm" />
                                        <span>
                                            <span class="tile-title">{{ $currency->value }}</span>
                                            <span class="tile-sub" style="display:block">{{ $currency->label() }}</span>
                                        </span>
                                        <span class="check"><x-icon name="check" /></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('currency')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                    </div>

                    <div class="card" id="method-card">
                        <div class="card-head" style="margin-bottom:14px">
                            <div>
                                <div class="card-title"><x-icon name="bank" /> 2. Payment method</div>
                                <div class="card-desc">“Automatic” lets the payment provider pick the best available method.</div>
                            </div>
                        </div>
                        <div class="chips" id="method-chips" data-old="{{ old('payment_method') }}" role="radiogroup" aria-label="Payment method"></div>
                        @error('payment_method')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                    </div>

                    <div class="card">
                        <div class="card-head" style="margin-bottom:14px">
                            <div class="card-title"><x-icon name="deposit" /> <span><span id="step-amount">3</span>. Amount and contact</span></div>
                        </div>
                        <div class="field">
                            <label for="amount">Amount</label>
                            <div class="affix">
                                <span class="prefix" id="amount-prefix">{{ $current }}</span>
                                <input id="amount" class="input-lg" type="text" name="amount" inputmode="decimal" value="{{ old('amount') }}" required maxlength="15" placeholder="0.00" autocomplete="off" @class(['is-invalid' => $errors->has('amount')])>
                            </div>
                            <div class="hint" id="amount-hint"></div>
                            @error('amount')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                        </div>
                        <div class="field mb-0">
                            <label for="phone">Mobile number</label>
                            <input id="phone" type="text" name="phone" inputmode="tel" value="{{ old('phone', $lastPhone) }}" required maxlength="20" autocomplete="tel" @class(['is-invalid' => $errors->has('phone')])>
                            <div class="hint" id="phone-hint"></div>
                            @error('phone')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <aside class="sticky">
                    <div class="card">
                        <div class="card-title" style="margin-bottom:14px"><x-icon name="file" /> Summary</div>
                        <div class="summary">
                            <div class="summary-row"><span>Currency</span><strong id="sum-currency">{{ $current }}</strong></div>
                            <div class="summary-row"><span>Method</span><strong id="sum-method">Automatic</strong></div>
                            <div class="summary-row summary-total"><span>You pay</span><strong id="sum-amount">—</strong></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg btn-block mt-16">Continue to payment <x-icon name="arrow-right" /></button>
                        <div class="trust">
                            <div><x-icon name="lock" /> You'll complete the payment on the provider's secure page.</div>
                            <div><x-icon name="check-circle" /> Your wallet is credited once the payment is confirmed.</div>
                            <div><x-icon name="clock" /> Not credited after paying? Open the transaction and press “Check status”.</div>
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
                var phoneExamples = { INR: '9876543210', PKR: '03001234567', BDT: '01712345678', USDT: '+1 555 123 4567' };
                var form = document.getElementById('deposit-form');
                var chips = document.getElementById('method-chips');
                var oldMethod = chips.dataset.old || '';

                function currency() {
                    var checked = form.querySelector('input[name=currency]:checked');
                    return checked ? checked.value : '';
                }

                function chip(value, label) {
                    var wrap = document.createElement('label');
                    wrap.className = 'chip';
                    var input = document.createElement('input');
                    input.type = 'radio';
                    input.name = 'payment_method';
                    input.value = value;
                    input.checked = value === oldMethod;
                    var span = document.createElement('span');
                    var dot = document.createElement('i');
                    dot.className = 'dot m-' + (value || 'AUTO');
                    span.appendChild(dot);
                    span.appendChild(document.createTextNode(label));
                    wrap.appendChild(input);
                    wrap.appendChild(span);
                    return wrap;
                }

                function refreshSummary() {
                    var code = currency();
                    var amount = document.getElementById('amount').value.trim();
                    var method = form.querySelector('input[name=payment_method]:checked');
                    document.getElementById('sum-currency').textContent = code;
                    document.getElementById('sum-method').textContent = method ? method.parentNode.textContent.trim() : (code === 'USDT' ? 'USDT (TRC20)' : 'Automatic');
                    document.getElementById('sum-amount').textContent = /^\d+(\.\d{1,2})?$/.test(amount) ? Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + code : '—';
                }

                function refresh() {
                    var code = currency();
                    var options = methods[code] || [];
                    chips.innerHTML = '';
                    document.getElementById('method-card').classList.toggle('hidden', options.length === 0);
                    document.getElementById('step-amount').textContent = options.length === 0 ? '2' : '3';
                    if (options.length > 0) {
                        chips.appendChild(chip('', 'Automatic'));
                        options.forEach(function (o) { chips.appendChild(chip(o.value, o.label)); });
                        if (!chips.querySelector('input:checked')) {
                            chips.querySelector('input').checked = true;
                        }
                    }
                    oldMethod = '';

                    var l = limits[code] || {};
                    var parts = [];
                    if (l.min) parts.push('Minimum ' + l.min + ' ' + code);
                    if (l.max) parts.push('maximum ' + l.max + ' ' + code);
                    document.getElementById('amount-hint').textContent = parts.join(' · ');
                    document.getElementById('amount-prefix').textContent = code;
                    document.getElementById('phone-hint').textContent = 'Example: ' + (phoneExamples[code] || '');
                    refreshSummary();
                }

                form.addEventListener('change', function (event) {
                    if (event.target.name === 'currency') { refresh(); } else { refreshSummary(); }
                });
                document.getElementById('amount').addEventListener('input', refreshSummary);
                refresh();
            })();
        </script>
    @endpush
@endif
