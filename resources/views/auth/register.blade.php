@extends('layouts.guest')

@section('title', 'Create account')

@section('content')
    <h1>Create your account</h1>
    <p class="sub">It takes less than a minute. Use your real name; it is sent as the payer name on deposits.</p>

    <form method="POST" action="{{ route('register') }}" data-lock>
        @csrf
        <div class="field">
            <label for="name">Full name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name" placeholder="e.g. Ali Khan" @class(['is-invalid' => $errors->has('name')])>
        </div>
        <div class="field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="191" autocomplete="username" placeholder="you@example.com" @class(['is-invalid' => $errors->has('email')])>
        </div>
        <div class="grid-2" style="gap:14px">
            <div class="field">
                <label for="password">Password</label>
                <div class="reveal">
                    <input id="password" type="password" name="password" required autocomplete="new-password" placeholder="Min. 8 characters" @class(['is-invalid' => $errors->has('password')])>
                    <button type="button" class="icon-btn" data-reveal="#password" aria-label="Show password"><x-icon name="eye" /></button>
                </div>
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Repeat password">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block mt-8">Create account <x-icon name="arrow-right" /></button>
    </form>

    <p class="auth-foot">Already have an account? <a href="{{ route('login') }}" class="strong">Log in</a></p>
@endsection
