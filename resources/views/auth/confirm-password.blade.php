@extends('layouts.guest')

@section('title', 'Confirm password')

@section('content')
    <div class="empty-icon" style="margin:0 0 18px"><x-icon name="lock" /></div>
    <h1>Confirm your password</h1>
    <p class="sub">This area holds payment credentials. Enter your password to continue; you won't be asked again for 15 minutes.</p>

    <form method="POST" action="{{ route('password.confirm') }}" data-lock>
        @csrf
        <div class="field">
            <label for="password">Password</label>
            <div class="reveal">
                <input id="password" type="password" name="password" required autofocus autocomplete="current-password" @class(['is-invalid' => $errors->has('password')])>
                <button type="button" class="icon-btn" data-reveal="#password" aria-label="Show password"><x-icon name="eye" /></button>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block">Confirm <x-icon name="arrow-right" /></button>
    </form>

    <p class="auth-foot"><a href="{{ route('dashboard') }}"><x-icon name="arrow-left" class="icon-sm" /> Back to dashboard</a></p>
@endsection
