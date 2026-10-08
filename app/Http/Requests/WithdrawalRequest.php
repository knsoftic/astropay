<?php

namespace App\Http\Requests;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Format checks only; per-method beneficiary rules (UPI ID / IFSC, wallet
 * mobile numbers, TRC20 checksum) are enforced by PayoutService.
 */
class WithdrawalRequest extends FormRequest
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
            'account' => ['required', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'account_phone' => ['nullable', 'string', 'max:25'],
            'person_name' => ['nullable', 'string', 'max:100'],
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

    /**
     * @return array{account: mixed, bank_code: mixed, account_phone: mixed, person_name: mixed}
     */
    public function beneficiary(): array
    {
        return [
            'account' => $this->validated('account'),
            'bank_code' => $this->validated('bank_code'),
            'account_phone' => $this->validated('account_phone'),
            'person_name' => $this->validated('person_name'),
        ];
    }
}
