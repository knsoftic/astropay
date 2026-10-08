@extends('layouts.app')

@section('title', 'Admin · UTR tools')

@section('content')
    <h1>UTR tools (INR / UPI)</h1>
    @include('admin._nav')

    @unless ($enabled)
        <div class="alert alert-warning">The INR account is not configured, so UTR tools are unavailable.</div>
    @endunless

    @if ($result = session('utr_result'))
        <div class="card">
            <h2>{{ $result['action'] === 'query' ? 'Query result' : 'Supplement result' }} · UTR <span class="mono">{{ $result['utr'] }}</span></h2>
            <pre>{{ json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @isset($result['transaction'])
                <p><a href="{{ route('admin.transactions.show', $result['transaction']) }}">Open the transaction</a> and use “Check status” in a few minutes.</p>
            @endisset
        </div>
    @endif

    <div class="card">
        <h2>Query a UTR</h2>
        <p class="muted">Looks up what the UPI channel knows about a bank reference.</p>
        <form method="POST" action="{{ route('admin.utr.query') }}" class="actions">
            @csrf
            <input type="text" name="utr" value="{{ old('utr') }}" required maxlength="32" placeholder="437558231943" style="max-width:260px" @disabled(! $enabled)>
            <button type="submit" class="btn" @disabled(! $enabled)>Query</button>
        </form>
    </div>

    <div class="card">
        <h2>Attach a UTR to a deposit</h2>
        <p class="muted">For INR deposits where the automatic UTR capture missed the customer's payment.</p>
        <form method="POST" action="{{ route('admin.utr.supplement') }}">
            @csrf
            <div class="field">
                <label for="order_id">Order ID</label>
                <input id="order_id" type="text" name="order_id" value="{{ old('order_id') }}" required maxlength="64" @disabled(! $enabled)>
                @error('order_id')<div class="error-text">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="supplement_utr">UTR</label>
                <input id="supplement_utr" type="text" name="utr" required maxlength="32" @disabled(! $enabled)>
                @error('utr')<div class="error-text">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn" @disabled(! $enabled)>Attach UTR</button>
        </form>
    </div>
@endsection
