@extends('layouts.app')

@section('title', 'UTR tools')
@section('subtitle', 'UPI reconciliation for the INR account')

@section('content')
    <div class="stack">
        @unless ($enabled)
            <div class="alert alert-warning mb-0"><x-icon name="alert" /><div class="body">The INR account has no saved keys, so UTR tools are unavailable. Add them in <a href="{{ route('admin.settings.edit') }}#inr">Settings</a>.</div></div>
        @endunless

        @if ($result = session('utr_result'))
            <div class="card">
                <div class="card-head">
                    <div class="card-title"><x-icon name="check-circle" /> {{ $result['action'] === 'query' ? 'Query result' : 'UTR attached' }}</div>
                    <span class="badge badge-primary no-dot mono">{{ $result['utr'] }}</span>
                </div>
                <pre>{{ json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                @isset($result['transaction'])
                    <p class="mt-16 mb-0"><a class="btn btn-secondary btn-sm" href="{{ route('admin.transactions.show', $result['transaction']) }}"><x-icon name="external" /> Open the transaction</a> <span class="muted small">and use “Check status” in a few minutes.</span></p>
                @endisset
            </div>
        @endif

        <div class="grid-2">
            <div class="card">
                <div class="card-head">
                    <div>
                        <div class="card-title"><x-icon name="search" /> Query a UTR</div>
                        <div class="card-desc">See what the UPI channel knows about a bank reference.</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.utr.query') }}" data-lock>
                    @csrf
                    <div class="field">
                        <label for="query_utr">UTR</label>
                        <input id="query_utr" type="text" name="utr" value="{{ old('utr') }}" required maxlength="32" placeholder="437558231943" autocomplete="off" @disabled(! $enabled)>
                    </div>
                    <button type="submit" class="btn btn-primary" @disabled(! $enabled)><x-icon name="search" /> Query</button>
                </form>
            </div>

            <div class="card">
                <div class="card-head">
                    <div>
                        <div class="card-title"><x-icon name="link" /> Attach a UTR to a deposit</div>
                        <div class="card-desc">For INR deposits where automatic UTR capture missed the payment.</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.utr.supplement') }}" data-lock>
                    @csrf
                    <div class="field">
                        <label for="order_id">Order ID</label>
                        <input id="order_id" type="text" name="order_id" value="{{ old('order_id') }}" required maxlength="64" autocomplete="off" @disabled(! $enabled) @class(['is-invalid' => $errors->has('order_id')])>
                        @error('order_id')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="supplement_utr">UTR</label>
                        <input id="supplement_utr" type="text" name="utr" required maxlength="32" autocomplete="off" @disabled(! $enabled)>
                        @error('utr')<div class="error-text"><x-icon name="alert" class="icon-sm" /> {{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary" @disabled(! $enabled)><x-icon name="link" /> Attach UTR</button>
                </form>
            </div>
        </div>
    </div>
@endsection
