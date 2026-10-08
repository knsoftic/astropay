@extends('layouts.app')

@section('title', 'Admin · Settings')

@section('content')
    <h1>Settings</h1>
    @include('admin._nav')

    <div class="alert alert-info">
        All AstroPay merchant data is stored in the database and managed only on this page.
        Merchant keys are stored encrypted and are never shown again after saving. Leave a key field empty to keep the saved value.
        Changing the keys of a currency affects its pending orders too: status checks and callbacks use the new keys.
    </div>

    @if (! $general['base_url'])
        <div class="alert alert-warning">The API base URL is not set. Save it below (AstroPay's documented URL is https://api.gpay.one).</div>
    @endif

    {{-- API connection --}}
    @php($generalErrors = $errors->getBag('general'))
    @php($useOldGeneral = old('form') === 'connection')
    <div class="card" id="connection">
        <h2>API connection</h2>

        @if ($generalErrors->any())
            <div class="alert alert-error"><ul>@foreach ($generalErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('admin.settings.general') }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="form" value="connection">

            <div class="field">
                <label for="base_url">API base URL</label>
                <input id="base_url" type="text" name="base_url" required maxlength="255" placeholder="https://api.gpay.one"
                       value="{{ $useOldGeneral ? old('base_url') : $general['base_url'] }}">
                <div class="hint">AstroPay's documented base URL is https://api.gpay.one. Only change it if AstroPay tells you to.</div>
            </div>

            <div class="field">
                <label for="callback_base_url">Callback base URL</label>
                <input id="callback_base_url" type="text" name="callback_base_url" maxlength="255" placeholder="https://pay.example.com"
                       value="{{ $useOldGeneral ? old('callback_base_url') : $general['callback_base_url'] }}">
                <div class="hint">Public HTTPS address of this site that AstroPay can reach. Empty = APP_URL / the current site address.</div>
            </div>

            <div class="field">
                <label for="webhook_allowed_ips">Allowed callback IPs</label>
                <textarea id="webhook_allowed_ips" name="webhook_allowed_ips" rows="3" maxlength="2000" placeholder="One IP or CIDR per line, e.g. 203.0.113.10 or 203.0.113.0/24">{{ $useOldGeneral ? old('webhook_allowed_ips') : $general['webhook_allowed_ips'] }}</textarea>
                <div class="hint">Optional. Empty = accept callbacks from any IP (the signature is always checked).</div>
            </div>

            <button type="submit" class="btn">Save connection settings</button>
        </form>
    </div>

    {{-- One card per currency account --}}
    @foreach ($accounts as $code => $account)
        @php($bag = $errors->getBag('account_'.$code))
        @php($useOld = old('form') === $code)
        <div class="card" id="{{ strtolower($code) }}">
            <div class="actions" style="justify-content:space-between;margin-bottom:12px">
                <h2 style="margin:0">{{ $account['currency']->label() }}</h2>
                <div class="actions">
                    @if ($account['enabled'])
                        <span class="badge badge-success">Enabled</span>
                    @elseif ($account['configured'])
                        <span class="badge badge-warning">Disabled</span>
                    @else
                        <span class="badge badge-muted">Not configured</span>
                    @endif
                </div>
            </div>

            @if ($account['decrypt_failed'])
                <div class="alert alert-error">The saved keys cannot be decrypted (APP_KEY has changed). Enter both keys again.</div>
            @endif

            @if ($bag->any())
                <div class="alert alert-error"><ul>@foreach ($bag->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif

            <form method="POST" action="{{ route('admin.settings.account', $code) }}" autocomplete="off">
                @csrf
                @method('PUT')
                <input type="hidden" name="form" value="{{ $code }}">

                <div class="grid" style="margin-bottom:0">
                    <div class="field">
                        <label for="merchant_key_{{ $code }}">Merchant key</label>
                        <input id="merchant_key_{{ $code }}" type="text" name="merchant_key" maxlength="255" autocomplete="off" spellcheck="false"
                               placeholder="{{ $account['merchant_key_hint'] ? 'Saved: '.$account['merchant_key_hint'].' (leave empty to keep)' : 'merchantKey from AstroPay' }}">
                    </div>
                    <div class="field">
                        <label for="secret_key_{{ $code }}">Secret key</label>
                        <input id="secret_key_{{ $code }}" type="password" name="secret_key" maxlength="255" autocomplete="new-password" spellcheck="false"
                               placeholder="{{ $account['secret_key_set'] ? 'Saved (hidden) — leave empty to keep' : 'secretKey from AstroPay' }}">
                    </div>
                </div>

                <div class="grid" style="margin-bottom:0">
                    @foreach (['deposit_min' => 'Deposit minimum', 'deposit_max' => 'Deposit maximum', 'payout_min' => 'Withdrawal minimum', 'payout_max' => 'Withdrawal maximum'] as $field => $label)
                        <div class="field">
                            <label for="{{ $field }}_{{ $code }}">{{ $label }}</label>
                            <input id="{{ $field }}_{{ $code }}" type="text" name="{{ $field }}" inputmode="decimal" maxlength="15" placeholder="No limit"
                                   value="{{ $useOld ? old($field) : $account[$field] }}">
                        </div>
                    @endforeach
                </div>
                <div class="hint" style="margin:-8px 0 16px">Empty = no local limit (AstroPay still enforces the limits set on your merchant account).</div>

                <div class="field">
                    <label class="inline">
                        <input type="checkbox" name="enabled" value="1" @checked($useOld ? old('enabled') : $account['enabled'])>
                        Enabled: accept new {{ $code }} deposits and withdrawals
                    </label>
                </div>

                @if ($account['configured'])
                    <div class="field">
                        <label class="inline" style="font-weight:400">
                            <input type="checkbox" name="clear_credentials" value="1"> Remove the saved keys for {{ $code }} (disables it)
                        </label>
                    </div>
                @endif

                <div class="actions">
                    <button type="submit" class="btn">Save {{ $code }}</button>
                </div>
            </form>

            @if ($account['configured'])
                <form method="POST" action="{{ route('admin.settings.test', $code) }}" style="margin-top:12px">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Test connection</button>
                    <span class="hint">Calls AstroPay's balance endpoint with the saved keys.</span>
                </form>
            @endif

            <div class="hint" style="margin-top:12px">
                @if ($account['callback_urls']['deposit'])
                    Callback URLs: <span class="mono">{{ $account['callback_urls']['deposit'] }}</span>,
                    <span class="mono">{{ $account['callback_urls']['payout'] }}</span><br>
                @endif
                @if ($account['updated_at'])
                    Last changed {{ $account['updated_at']->format('Y-m-d H:i') }}
                    @if ($account['updated_by'] && isset($updaters[$account['updated_by']]))
                        by {{ $updaters[$account['updated_by']] }}
                    @endif
                @endif
            </div>
        </div>
    @endforeach
@endsection
