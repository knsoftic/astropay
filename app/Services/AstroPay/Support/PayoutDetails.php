<?php

namespace App\Services\AstroPay\Support;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises payout beneficiary fields per method, following
 * Reference → Payment Method Codes ("What to put in each payout field"):
 *
 *  INR  UPI                 account = UPI ID or bank account no., bank_code = IFSC (bank account only) else "", accountPhone = phone
 *  PKR  EASYPAISA/JAZZCASH  account = wallet mobile, bank_code = "", accountPhone = same mobile
 *  BDT  BKASH/NAGAD         account = wallet mobile, bank_code = "", accountPhone = same mobile
 *  USDT (no accountType)    account = TRC20 address, bank_code/accountPhone not used
 */
final class PayoutDetails
{
    private const UPI_ID_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,255}@[a-zA-Z][a-zA-Z0-9.]{1,63}$/';

    private const BANK_ACCOUNT_PATTERN = '/^\d{9,18}$/';

    private const IFSC_PATTERN = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    private const NAME_PATTERN = "/^[\\p{L}][\\p{L}\\p{M} .'\\-]{1,99}$/u";

    /**
     * @param  array{account?: mixed, bank_code?: mixed, account_phone?: mixed, person_name?: mixed}  $input
     * @return array{account: string, bank_code: string, account_phone: string, person_name: string}
     *
     * @throws ValidationException
     */
    public static function normalize(Currency $currency, ?PaymentMethod $method, array $input): array
    {
        $account = self::string($input['account'] ?? null);
        $bankCode = strtoupper(self::string($input['bank_code'] ?? null));
        $accountPhone = self::string($input['account_phone'] ?? null);
        $personName = preg_replace('/\s+/u', ' ', self::string($input['person_name'] ?? null)) ?? '';

        self::assertMethod($currency, $method);

        $details = match ($currency) {
            Currency::INR => self::india($account, $bankCode, $accountPhone),
            Currency::PKR => self::wallet($account, PhoneNumber::pakistan(...), 'Enter the Pakistani wallet mobile number, e.g. 03001234567.'),
            Currency::BDT => self::wallet($account, PhoneNumber::bangladesh(...), 'Enter the Bangladeshi wallet mobile number, e.g. 01712345678.'),
            Currency::USDT => self::usdt($account),
        };

        if ($personName === '' && $currency !== Currency::USDT) {
            throw ValidationException::withMessages(['person_name' => 'Enter the beneficiary name.']);
        }

        if ($personName !== '' && preg_match(self::NAME_PATTERN, $personName) !== 1) {
            throw ValidationException::withMessages(['person_name' => 'The beneficiary name may only contain letters, spaces, dots, apostrophes and dashes.']);
        }

        return $details + ['person_name' => $personName];
    }

    public static function assertMethod(Currency $currency, ?PaymentMethod $method, string $field = 'payment_method'): void
    {
        if (! $currency->usesPaymentMethods()) {
            if ($method !== null) {
                throw ValidationException::withMessages([$field => 'USDT does not use a payment method.']);
            }

            return;
        }

        if ($method === null) {
            throw ValidationException::withMessages([$field => 'Select a payment method.']);
        }

        if ($method->currency() !== $currency) {
            throw ValidationException::withMessages([$field => sprintf('%s is not available for %s.', $method->label(), $currency->value)]);
        }
    }

    /**
     * @return array{account: string, bank_code: string, account_phone: string}
     */
    private static function india(string $account, string $bankCode, string $accountPhone): array
    {
        $phone = PhoneNumber::india($accountPhone);

        if ($phone === null) {
            throw ValidationException::withMessages(['account_phone' => 'Enter a valid 10-digit Indian mobile number.']);
        }

        if (preg_match(self::UPI_ID_PATTERN, $account) === 1) {
            // UPI ID: bank_code is not used.
            return ['account' => $account, 'bank_code' => '', 'account_phone' => $phone];
        }

        $bankAccount = preg_replace('/\s+/', '', $account) ?? '';

        if (preg_match(self::BANK_ACCOUNT_PATTERN, $bankAccount) !== 1) {
            throw ValidationException::withMessages(['account' => 'Enter a UPI ID (name@bank) or a 9–18 digit bank account number.']);
        }

        if (preg_match(self::IFSC_PATTERN, $bankCode) !== 1) {
            throw ValidationException::withMessages(['bank_code' => 'A valid IFSC code (e.g. HDFC0000123) is required for bank account payouts.']);
        }

        return ['account' => $bankAccount, 'bank_code' => $bankCode, 'account_phone' => $phone];
    }

    /**
     * @param  callable(string): ?string  $normalizer
     * @return array{account: string, bank_code: string, account_phone: string}
     */
    private static function wallet(string $account, callable $normalizer, string $message): array
    {
        $mobile = $normalizer($account);

        if ($mobile === null) {
            throw ValidationException::withMessages(['account' => $message]);
        }

        // accountPhone must be the same mobile number as account.
        return ['account' => $mobile, 'bank_code' => '', 'account_phone' => $mobile];
    }

    /**
     * @return array{account: string, bank_code: string, account_phone: string}
     */
    private static function usdt(string $account): array
    {
        if (! TronAddress::isValid($account)) {
            throw ValidationException::withMessages(['account' => 'Enter a valid USDT TRC20 address (34 characters starting with "T").']);
        }

        return ['account' => $account, 'bank_code' => '', 'account_phone' => ''];
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
