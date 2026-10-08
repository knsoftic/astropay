<?php

namespace App\Http\Requests;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Format checks only; currency-specific rules (enabled account, method per
 * currency, limits, phone format) are enforced by DepositService.
 */
class DepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', Rule::enum(Currency::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'amount' => ['required', 'string', 'max:20'],
            'phone' => ['required', 'string', 'max:25'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'payment_method' => $this->input('payment_method') === '' ? null : $this->input('payment_method'),
            'amount' => is_string($this->input('amount')) ? trim($this->input('amount')) : $this->input('amount'),
        ]);
    }

    public function currency(): Currency
    {
        return Currency::from((string) $this->validated('currency'));
    }

    public function paymentMethod(): ?PaymentMethod
    {
        $value = $this->validated('payment_method');

        return $value === null ? null : PaymentMethod::from((string) $value);
    }
}
