<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Re-enter the password before sensitive pages (Admin → Settings).
 */
class ConfirmPasswordController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function show(): View
    {
        return view('auth.confirm-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:255']]);

        $throttleKey = 'confirm-password:'.$request->user()->getKey();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'password' => sprintf('Too many attempts. Try again in %d seconds.', RateLimiter::availableIn($throttleKey)),
            ]);
        }

        if (! Hash::check($request->input('password'), $request->user()->getAuthPassword())) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->passwordConfirmed();

        return redirect()->intended(route('dashboard'));
    }
}
