@php
    $user = auth()->user();
    $initials = collect(preg_split('/\s+/', trim((string) $user?->name)) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'U';
    $isApprovals = request()->routeIs('admin.transactions.index') && request('status') === 'awaiting_approval';
    $isReviews = request()->routeIs('admin.transactions.index') && request()->boolean('review');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b1020">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
</head>
<body>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="brand" href="{{ route('dashboard') }}">
        <span class="brand-mark"><x-icon name="zap" /></span>
        <span>{{ config('app.name') }}<small>Payments & wallet</small></span>
    </a>

    <div class="nav-label">Wallet</div>
    <nav>
        <a href="{{ route('dashboard') }}" @class(['nav-link', 'active' => request()->routeIs('dashboard')])><x-icon name="dashboard" /> Dashboard</a>
        <a href="{{ route('deposits.create') }}" @class(['nav-link', 'active' => request()->routeIs('deposits.*')])><x-icon name="deposit" /> Deposit</a>
        <a href="{{ route('withdrawals.create') }}" @class(['nav-link', 'active' => request()->routeIs('withdrawals.*')])><x-icon name="withdraw" /> Withdraw</a>
        <a href="{{ route('transactions.index') }}" @class(['nav-link', 'active' => request()->routeIs('transactions.*')])><x-icon name="list" /> Transactions</a>
    </nav>

    @if ($user?->isAdmin())
        <div class="nav-label">Admin</div>
        <nav>
            <a href="{{ route('admin.dashboard') }}" @class(['nav-link', 'active' => request()->routeIs('admin.dashboard', 'admin.balance')])><x-icon name="shield" /> Overview</a>
            <a href="{{ route('admin.transactions.index', ['type' => 'payout', 'status' => 'awaiting_approval']) }}" @class(['nav-link', 'active' => $isApprovals])>
                <x-icon name="check-circle" /> Approvals
                @if (($navCounts['approvals'] ?? 0) > 0)<span class="count">{{ $navCounts['approvals'] }}</span>@endif
            </a>
            <a href="{{ route('admin.transactions.index') }}" @class(['nav-link', 'active' => request()->routeIs('admin.transactions.*') && ! $isApprovals && ! $isReviews])><x-icon name="list" /> All transactions</a>
            <a href="{{ route('admin.transactions.index', ['review' => 1]) }}" @class(['nav-link', 'active' => $isReviews])>
                <x-icon name="alert" /> Reviews
                @if (($navCounts['reviews'] ?? 0) > 0)<span class="count">{{ $navCounts['reviews'] }}</span>@endif
            </a>
            <a href="{{ route('admin.webhooks.index') }}" @class(['nav-link', 'active' => request()->routeIs('admin.webhooks.*')])><x-icon name="activity" /> Callbacks</a>
            <a href="{{ route('admin.utr.index') }}" @class(['nav-link', 'active' => request()->routeIs('admin.utr.*')])><x-icon name="hash" /> UTR tools</a>
            <a href="{{ route('admin.settings.edit') }}" @class(['nav-link', 'active' => request()->routeIs('admin.settings.*')])><x-icon name="settings" /> Settings</a>
        </nav>
    @endif

    <div class="sidebar-footer">
        <div class="user-card">
            <div class="avatar">{{ $initials }}</div>
            <div class="who">
                <strong>{{ $user?->name }}</strong>
                <span>{{ $user?->isAdmin() ? 'Administrator' : $user?->email }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="icon-btn" title="Log out" aria-label="Log out"><x-icon name="logout" /></button>
            </form>
        </div>
    </div>
</aside>
<div class="backdrop" data-nav-close></div>

<div class="main">
    <header class="topbar">
        <button type="button" class="icon-btn menu-toggle" data-nav-toggle aria-label="Open menu" aria-controls="sidebar"><x-icon name="menu" /></button>
        <div class="titles">
            <h1>@yield('title', 'Dashboard')</h1>
            @hasSection('subtitle')
                <div class="subtitle">@yield('subtitle')</div>
            @endif
        </div>
        @hasSection('actions')
            <div class="actions">@yield('actions')</div>
        @endif
    </header>

    <main class="content">
        @include('partials.flash')
        @yield('content')
    </main>
</div>

<script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}" defer></script>
@stack('scripts')
</body>
</html>
