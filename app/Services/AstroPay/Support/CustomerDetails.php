<?php

namespace App\Services\AstroPay\Support;

use App\Enums\AstroPay\Currency;
use Illuminate\Validation\ValidationException;

/**
 * Customer fields required by Create Deposit Order (name, phone, email).
 */
final class CustomerDetails
{
    /**
     * @param  array{name?: mixed, phone?: mixed, email?: mixed}  $input
     * @return array{name: string, phone: string, email: string}
     *
     * @throws ValidationException
     */
    public static function normalize(Currency $currency, array $input): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', is_scalar($input['name'] ?? null) ? (string) $input['name'] : '') ?? '');
        $email = strtolower(trim(is_scalar($input['email'] ?? null) ? (string) $input['email'] : ''));
        $phoneInput = is_scalar($input['phone'] ?? null) ? (string) $input['phone'] : '';

        $errors = [];

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter a name of up to 100 characters.';
        }

        if ($email === '' || mb_strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $phone = PhoneNumber::forCurrency($currency, $phoneInput);

        if ($phone === null) {
            $errors['phone'] = 'Enter a valid mobile number, e.g. '.PhoneNumber::exampleFor($currency).'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['name' => $name, 'phone' => $phone, 'email' => $email];
    }
}
