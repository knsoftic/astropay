@extends('layouts.guest')

@section('title', 'Log in')

@section('content')
    <h1>Welcome back</h1>
    <p class="sub">Log in to manage your wallet, deposits and withdrawals.</p>

    <form method="POST" action="{{ route('login') }}" data-lock>
        @csrf
        <div class="field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="you@example.com" @class(['is-invalid' => $errors->has('email')])>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="reveal">
                <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Your password">
                <button type="button" class="icon-btn" data-reveal="#password" aria-label="Show password"><x-icon name="eye" /></button>
            </div>
        </div>
        <div class="row-between" style="margin-bottom:22px">
            <label class="checkbox"><input type="checkbox" name="remember" value="1"> Keep me logged in</label>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block">Log in <x-icon name="arrow-right" /></button>
    </form>

    <p class="auth-foot">New here? <a href="{{ route('register') }}" class="strong">Create an account</a></p>
@endsection
