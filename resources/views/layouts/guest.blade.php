<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b1020">
    <title>@yield('title', 'Welcome') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
</head>
<body>
<div class="auth">
    <section class="auth-brand" aria-hidden="true">
        <a class="brand" href="{{ url('/') }}">
            <span class="brand-mark"><x-icon name="zap" /></span>
            <span>{{ config('app.name') }}</span>
        </a>

        <div>
            <h2>One wallet for <span>INR, PKR, BDT and USDT</span>.</h2>
            <p class="lead">Deposit through UPI, JazzCash, Easypaisa, bKash, Nagad or USDT, and withdraw to your own account.</p>
        </div>

        <div class="auth-features">
            <div class="auth-feature">
                <div class="fi"><x-icon name="lock" /></div>
                <div><strong>Secure checkout</strong><span>Payments are completed on the provider's hosted page.</span></div>
            </div>
            <div class="auth-feature">
                <div class="fi"><x-icon name="check-circle" /></div>
                <div><strong>Confirmed before credit</strong><span>Your wallet is credited only after the payment is verified.</span></div>
            </div>
            <div class="auth-feature">
                <div class="fi"><x-icon name="refresh" /></div>
                <div><strong>Automatic refunds</strong><span>A failed withdrawal returns the full amount to your wallet.</span></div>
            </div>
            <div class="auth-coins">
                <x-coin currency="INR" size="sm" />
                <x-coin currency="PKR" size="sm" />
                <x-coin currency="BDT" size="sm" />
                <x-coin currency="USDT" size="sm" />
            </div>
        </div>
    </section>

    <main class="auth-form">
        <div class="auth-card">
            <div class="auth-mobile-brand">
                <a class="brand" href="{{ url('/') }}">
                    <span class="brand-mark"><x-icon name="zap" /></span>
                    <span>{{ config('app.name') }}</span>
                </a>
            </div>
            @include('partials.flash')
            @yield('content')
        </div>
    </main>
</div>
<script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
