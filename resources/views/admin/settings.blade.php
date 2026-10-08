@extends('layouts.app')

@section('title', 'Settings')
@section('subtitle', 'AstroPay merchant accounts and API connection')

@section('content')
    <div class="stack">
        <div class="callout">
            <x-icon name="lock" />
            <div>
                All AstroPay merchant data is stored in the database and managed only on this page.
                Keys are encrypted and never shown again after saving; leave a key field empty to keep the saved value.
                Changing the keys of a currency also affects its pending orders.
            </div>
        </div>

        @if (! $general['base_url'])
            <div class="alert alert-warning mb-0"><x-icon name="alert" /><div class="body">The API base URL is not set. Save it below (AstroPay's documented URL is https://api.gpay.one).</div></div>
        @endif

        {{-- API connection --}}
        @php($generalErrors = $errors->getBag('general'))
        @php($useOldGeneral = old('form') === 'connection')
        <div class="card" id="connection">
            <div class="card-head">
                <div>
                    <div class="card-title"><x-icon name="globe" /> API connection</div>
                    <div class="card-desc">Where requests go and where AstroPay sends callbacks</div>
                </div>
            </div>

            @if ($generalErrors->any())
                <div class="alert alert-error"><x-icon name="x-circle" /><div class="body"><ul class="mt-0">@foreach ($generalErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
            @endif

            <form method="POST" action="{{ route('admin.settings.general') }}" data-lock>
                @csrf
                @method('PUT')
                <input type="hidden" name="form" value="connection">

                <div class="grid-2" style="gap:16px">
                    <div class="field">
                        <label for="base_url">API base URL</label>
                        <input id="base_url" type="text" name="base_url" required maxlength="255" placeholder="https://api.gpay.one"
                               value="{{ $useOldGeneral ? old('base_url') : $general['base_url'] }}">
                        <div class="hint">AstroPay's documented URL is https://api.gpay.one. Change it only if AstroPay asks you to.</div>
                    </div>
                    <div class="field">
                        <label for="callback_base_url">Callback base URL</label>
                        <input id="callback_base_url" type="text" name="callback_base_url" maxlength="255" placeholder="https://pay.example.com"
                               value="{{ $useOldGeneral ? old('callback_base_url') : $general['callback_base_url'] }}">
                        <div class="hint">Public HTTPS address of this site. Empty = the site's own URL.</div>
                    </div>
                </div>

                <div class="field">
                    <label for="webhook_allowed_ips">Allowed callback IPs <span class="muted" style="font-weight:400">(optional)</span></label>
                    <textarea id="webhook_allowed_ips" name="webhook_allowed_ips" rows="3" maxlength="2000" placeholder="One IP or CIDR per line, e.g. 203.0.113.10 or 203.0.113.0/24">{{ $useOldGeneral ? old('webhook_allowed_ips') : $general['webhook_allowed_ips'] }}</textarea>
                    <div class="hint">Empty = accept callbacks from any IP. The signature is always checked.</div>
                </div>

                <button type="submit" class="btn btn-primary"><x-icon name="check" /> Save connection settings</button>
            </form>
        </div>

        {{-- Merchant accounts --}}
        <div>
            <div class="section-title">Merchant accounts</div>
            <div class="grid-2">
                @foreach ($accounts as $code => $account)
                    @php($bag = $errors->getBag('account_'.$code))
                    @php($useOld = old('form') === $code)
                    <div class="card" id="{{ strtolower($code) }}">
                        <div class="card-head">
                            <div class="row">
                                <x-coin :currency="$account['currency']" />
                                <div>
                                    <div class="card-title" style="font-size:16px">{{ $account['currency']->label() }}</div>
                                    <div class="card-desc">
                                        @if ($account['updated_at'])
                                            Changed {{ $account['updated_at']->format('d M Y, H:i') }}
                                            @if ($account['updated_by'] && isset($updaters[$account['updated_by']]))
                                                by {{ $updaters[$account['updated_by']] }}
                                            @endif
                                        @else
                                            Not saved yet
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @if ($account['enabled'])
                                <span class="badge badge-success">Enabled</span>
                            @elseif ($account['configured'])
                                <span class="badge badge-warning">Disabled</span>
                            @else
                                <span class="badge badge-muted">Not configured</span>
                            @endif
                        </div>

                        @if ($account['decrypt_failed'])
                            <div class="alert alert-error"><x-icon name="key" /><div class="body">The saved keys cannot be decrypted (APP_KEY has changed). Enter both keys again.</div></div>
                        @endif

                        @if ($bag->any())
                            <div class="alert alert-error"><x-icon name="x-circle" /><div class="body"><ul class="mt-0">@foreach ($bag->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
                        @endif

                        <form method="POST" action="{{ route('admin.settings.account', $code) }}" autocomplete="off" id="account-{{ $code }}" data-lock>
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="form" value="{{ $code }}">

                            <div class="field">
                                <label for="merchant_key_{{ $code }}">Merchant key</label>
                                <input id="merchant_key_{{ $code }}" type="text" name="merchant_key" maxlength="255" autocomplete="off" spellcheck="false"
                                       placeholder="{{ $account['merchant_key_hint'] ? 'Saved: '.$account['merchant_key_hint'].' (leave empty to keep)' : 'merchantKey from AstroPay' }}">
                            </div>
                            <div class="field">
                                <label for="secret_key_{{ $code }}">Secret key</label>
                                <div class="reveal">
                                    <input id="secret_key_{{ $code }}" type="password" name="secret_key" maxlength="255" autocomplete="new-password" spellcheck="false"
                                           placeholder="{{ $account['secret_key_set'] ? 'Saved (hidden) — leave empty to keep' : 'secretKey from AstroPay' }}">
                                    <button type="button" class="icon-btn" data-reveal="#secret_key_{{ $code }}" aria-label="Show secret key"><x-icon name="eye" /></button>
                                </div>
                            </div>

                            <div class="grid-2" style="gap:12px">
                                @foreach (['deposit_min' => 'Deposit min', 'deposit_max' => 'Deposit max', 'payout_min' => 'Withdrawal min', 'payout_max' => 'Withdrawal max'] as $field => $label)
                                    <div class="field" style="margin-bottom:12px">
                                        <label for="{{ $field }}_{{ $code }}">{{ $label }}</label>
                                        <input id="{{ $field }}_{{ $code }}" type="text" name="{{ $field }}" inputmode="decimal" maxlength="15" placeholder="No limit"
                                               value="{{ $useOld ? old($field) : $account[$field] }}">
                                    </div>
                                @endforeach
                            </div>
                            <div class="hint" style="margin:-4px 0 16px">Empty = no local limit; AstroPay still enforces your account's limits.</div>

                            <div class="field">
                                <label class="switch">
                                    <input type="checkbox" name="enabled" value="1" @checked($useOld ? old('enabled') : $account['enabled'])>
                                    <span>Enabled<small>Accept new {{ $code }} deposits and withdrawals</small></span>
                                </label>
                            </div>

                            @if ($account['configured'])
                                <div class="field">
                                    <label class="checkbox" style="color:var(--danger);font-weight:500">
                                        <input type="checkbox" name="clear_credentials" value="1"> Remove the saved keys for {{ $code }} (disables it)
                                    </label>
                                </div>
                            @endif
                        </form>

                        @if ($account['configured'])
                            <form method="POST" action="{{ route('admin.settings.test', $code) }}" id="test-{{ $code }}" data-lock>
                                @csrf
                            </form>
                        @endif

                        <div class="row wrap" style="gap:10px;margin-top:4px">
                            <button type="submit" form="account-{{ $code }}" class="btn btn-primary"><x-icon name="check" /> Save {{ $code }}</button>
                            @if ($account['configured'])
                                <button type="submit" form="test-{{ $code }}" class="btn btn-secondary"><x-icon name="zap" /> Test connection</button>
                            @endif
                        </div>

                        @if ($account['callback_urls']['deposit'])
                            <hr>
                            <div class="small muted" style="margin-bottom:6px">Callback URLs (sent with every order)</div>
                            <div class="copy-field" style="margin-bottom:6px"><code>{{ $account['callback_urls']['deposit'] }}</code></div>
                            <div class="copy-field"><code>{{ $account['callback_urls']['payout'] }}</code></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
