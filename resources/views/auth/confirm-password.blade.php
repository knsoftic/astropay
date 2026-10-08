@extends('layouts.app')

@section('title', 'Confirm password')
@section('container_class', 'narrow')

@section('content')
    <div class="card">
        <h1>Confirm your password</h1>
        <p class="muted">This area contains payment credentials. Please enter your password to continue.</p>
        <form method="POST" action="{{ route('password.confirm') }}">
            @csrf
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autofocus autocomplete="current-password">
            </div>
            <button type="submit" class="btn">Confirm</button>
        </form>
    </div>
@endsection
