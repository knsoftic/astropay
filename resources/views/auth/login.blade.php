@extends('layouts.app')

@section('title', 'Log in')
@section('container_class', 'narrow')

@section('content')
    <div class="card">
        <h1>Log in</h1>
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label class="inline"><input type="checkbox" name="remember" value="1"> Remember me</label>
            </div>
            <div class="actions">
                <button type="submit" class="btn">Log in</button>
                <a href="{{ route('register') }}">Create an account</a>
            </div>
        </form>
    </div>
@endsection
