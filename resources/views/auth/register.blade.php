@extends('layouts.app')

@section('title', 'Register')
@section('container_class', 'narrow')

@section('content')
    <div class="card">
        <h1>Create an account</h1>
        <form method="POST" action="{{ route('register') }}">
            @csrf
            <div class="field">
                <label for="name">Full name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name">
                <div class="hint">Used as the payer name on deposits.</div>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="191" autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>
            <div class="actions">
                <button type="submit" class="btn">Register</button>
                <a href="{{ route('login') }}">Already registered?</a>
            </div>
        </form>
    </div>
@endsection
