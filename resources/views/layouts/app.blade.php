<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Wallet') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="{{ url('/') }}">{{ config('app.name') }}</a>
        @auth
            <nav class="nav">
                <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Dashboard</a>
                <a href="{{ route('deposits.create') }}" @class(['active' => request()->routeIs('deposits.*')])>Deposit</a>
                <a href="{{ route('withdrawals.create') }}" @class(['active' => request()->routeIs('withdrawals.*')])>Withdraw</a>
                <a href="{{ route('transactions.index') }}" @class(['active' => request()->routeIs('transactions.*')])>Transactions</a>
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" @class(['active' => request()->routeIs('admin.*')])>Admin</a>
                @endif
            </nav>
            <div class="nav-user">
                <span>{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="btn-link">Log out</button>
                </form>
            </div>
        @endauth
    </div>
</header>

<main class="container @yield('container_class')">
    @include('partials.flash')
    @yield('content')
</main>

@stack('scripts')
</body>
</html>
