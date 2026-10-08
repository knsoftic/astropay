<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\AstroPayException;
use App\Exceptions\AstroPay\GatewayRequestException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AstroPay\AstroPayManager;
use App\Services\AstroPay\AstroPaySettings;
use App\Services\AstroPay\Support\Amount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Admin → Settings: AstroPay merchant credentials per currency and the API
 * connection settings. Secrets are stored encrypted and never shown again.
 */
class AdminSettingsController extends Controller
{
    public function edit(AstroPaySettings $settings, AstroPayManager $astropay): View
    {
        $accounts = [];
        $updaterIds = [];

        foreach (Currency::cases() as $currency) {
            $account = $settings->account($currency);
            $accounts[$currency->value] = [
                'currency' => $currency,
                'enabled' => $astropay->isEnabled($currency),
                'configured' => $astropay->hasCredentials($currency),
                'merchant_key_hint' => self::hint($account['merchant_key']),
                'secret_key_set' => $account['secret_key'] !== null,
                'decrypt_failed' => $account['decrypt_failed'],
                'deposit_min' => $account['deposit_min'],
                'deposit_max' => $account['deposit_max'],
                'payout_min' => $account['payout_min'],
                'payout_max' => $account['payout_max'],
                'updated_at' => $account['updated_at'],
                'updated_by' => $account['updated_by'],
                'callback_urls' => $this->callbackUrls($astropay, $currency),
            ];

            if ($account['updated_by'] !== null) {
                $updaterIds[] = $account['updated_by'];
            }
        }

        return view('admin.settings', [
            'accounts' => $accounts,
            'updaters' => User::query()->whereIn('id', array_unique($updaterIds))->pluck('email', 'id'),
            'general' => [
                'base_url' => $settings->baseUrl(),
                'callback_base_url' => $settings->callbackBaseUrl(),
                'webhook_allowed_ips' => implode("\n", $settings->webhookAllowedIps()),
            ],
        ]);
    }

    public function updateGeneral(Request $request, AstroPaySettings $settings, AstroPayManager $astropay): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'base_url' => ['required', 'string', 'max:255', 'url:https'],
            'callback_base_url' => ['nullable', 'string', 'max:255', app()->isProduction() ? 'url:https' : 'url:http,https'],
            'webhook_allowed_ips' => ['nullable', 'string', 'max:2000'],
        ], [
            'base_url.url' => 'The API base URL must be a valid https:// URL.',
            'callback_base_url.url' => app()->isProduction()
                ? 'The callback base URL must be a public https:// URL.'
                : 'The callback base URL must be a valid http(s):// URL.',
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach (['base_url', 'callback_base_url'] as $field) {
                $value = (string) $request->input($field, '');

                if ($value !== '' && (str_contains($value, '?') || str_contains($value, '#'))) {
                    $validator->errors()->add($field, 'The URL must not contain a query string or fragment.');
                }
            }

            foreach (self::parseIps((string) $request->input('webhook_allowed_ips', '')) as $ip) {
                if (! self::isValidIpOrCidr($ip)) {
                    $validator->errors()->add('webhook_allowed_ips', "\"{$ip}\" is not a valid IP address or CIDR range.");
                }
            }
        });

        $data = $validator->validateWithBag('general');

        $values = [
            AstroPaySettings::BASE_URL => rtrim(trim($data['base_url']), '/'),
            AstroPaySettings::CALLBACK_BASE_URL => filled($data['callback_base_url'] ?? null) ? rtrim(trim($data['callback_base_url']), '/') : null,
            AstroPaySettings::WEBHOOK_ALLOWED_IPS => self::parseIps((string) ($data['webhook_allowed_ips'] ?? '')),
        ];

        $settings->saveGeneral($values, $request->user());

        $astropay->logger()->notice('AstroPay connection settings updated', [
            'admin_id' => $request->user()->getKey(),
            'base_url' => $values[AstroPaySettings::BASE_URL],
            'callback_base_url' => $values[AstroPaySettings::CALLBACK_BASE_URL],
            'webhook_allowed_ips' => $values[AstroPaySettings::WEBHOOK_ALLOWED_IPS],
        ]);

        return redirect()->route('admin.settings.edit')->withFragment('connection')->with('success', 'API connection settings saved.');
    }

    public function updateAccount(Request $request, string $currency, AstroPaySettings $settings, AstroPayManager $astropay): RedirectResponse
    {
        $currency = Currency::from($currency);
        $bag = 'account_'.$currency->value;
        $amountRule = ['nullable', 'string', 'max:15', 'regex:'.Amount::INPUT_PATTERN];

        $validator = Validator::make($request->all(), [
            'merchant_key' => ['nullable', 'string', 'max:255', 'regex:/^\S+$/'],
            'secret_key' => ['nullable', 'string', 'max:255', 'regex:/^\S+$/'],
            'enabled' => ['nullable', 'boolean'],
            'clear_credentials' => ['nullable', 'boolean'],
            'deposit_min' => $amountRule,
            'deposit_max' => $amountRule,
            'payout_min' => $amountRule,
            'payout_max' => $amountRule,
        ], [
            'merchant_key.regex' => 'The merchant key must not contain spaces.',
            'secret_key.regex' => 'The secret key must not contain spaces.',
            '*.regex' => 'Enter an amount with at most 2 decimal places.',
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach (['deposit', 'payout'] as $type) {
                $min = $request->input($type.'_min');
                $max = $request->input($type.'_max');

                if (filled($min) && filled($max) && Amount::of($min) !== null && Amount::of($max) !== null && Amount::compare($min, $max) > 0) {
                    $validator->errors()->add($type.'_max', ucfirst($type).' maximum must be greater than or equal to the minimum.');
                }
            }
        });

        $data = $validator->validateWithBag($bag);
        $current = $settings->account($currency);
        $clear = $request->boolean('clear_credentials');

        $merchantKey = $clear ? null : (filled($data['merchant_key'] ?? null) ? trim($data['merchant_key']) : $current['merchant_key']);
        $secretKey = $clear ? null : (filled($data['secret_key'] ?? null) ? trim($data['secret_key']) : $current['secret_key']);
        $enabled = ! $clear && $request->boolean('enabled');

        if ($enabled && ($merchantKey === null || $secretKey === null)) {
            throw ValidationException::withMessages([
                'merchant_key' => 'Enter both the merchant key and the secret key before enabling '.$currency->value.'.',
            ])->errorBag($bag);
        }

        $settings->saveAccount($currency, [
            'merchant_key' => $merchantKey,
            'secret_key' => $secretKey,
            'enabled' => $enabled,
            'deposit_min' => self::amountOrNull($data['deposit_min'] ?? null),
            'deposit_max' => self::amountOrNull($data['deposit_max'] ?? null),
            'payout_min' => self::amountOrNull($data['payout_min'] ?? null),
            'payout_max' => self::amountOrNull($data['payout_max'] ?? null),
        ], $request->user());

        // Never log key values, only whether they changed.
        $astropay->logger()->notice('AstroPay account settings updated', [
            'admin_id' => $request->user()->getKey(),
            'currency' => $currency->value,
            'merchant_key_changed' => $merchantKey !== $current['merchant_key'],
            'secret_key_changed' => $secretKey !== $current['secret_key'],
            'credentials_cleared' => $clear,
            'enabled' => $enabled,
        ]);

        $message = match (true) {
            $clear => $currency->value.' credentials removed; the currency is disabled.',
            $enabled => $currency->value.' settings saved. The currency is enabled. Use "Test connection" to verify the keys.',
            default => $currency->value.' settings saved. The currency is disabled for new orders.',
        };

        return redirect()->route('admin.settings.edit')->withFragment(strtolower($currency->value))->with('success', $message);
    }

    /**
     * Verify the saved keys with POST /v1/account/balance.
     */
    public function testAccount(string $currency, AstroPayManager $astropay): RedirectResponse
    {
        $currency = Currency::from($currency);
        $redirect = redirect()->route('admin.settings.edit')->withFragment(strtolower($currency->value));

        try {
            $data = $astropay->client($currency)->balance()->data;
        } catch (GatewayRequestException $e) {
            $hint = match ($e->gatewayCode) {
                401 => 'The merchant key or secret key is wrong, or the account is disabled.',
                403 => 'This server\'s IP address is not whitelisted. Send it to AstroPay support.',
                404 => 'AstroPay does not know this merchant.',
                default => $e->getMessage(),
            };

            return $redirect->with('error', sprintf('%s: connection failed (code %d). %s', $currency->value, $e->gatewayCode, $hint));
        } catch (AstroPayException $e) {
            return $redirect->with('error', sprintf('%s: connection failed. %s', $currency->value, $e->getMessage()));
        }

        return $redirect->with('success', sprintf(
            '%s: connected. MID %s, available balance %s, frozen %s.',
            $currency->value,
            is_scalar($data['MID'] ?? null) ? $data['MID'] : '—',
            is_scalar($data['Balance'] ?? null) ? Amount::format($data['Balance']) : '—',
            is_scalar($data['FreezeBalance'] ?? null) ? Amount::format($data['FreezeBalance']) : '—',
        ));
    }

    /**
     * @return array{deposit: string|null, payout: string|null}
     */
    private function callbackUrls(AstroPayManager $astropay, Currency $currency): array
    {
        try {
            return [
                'deposit' => $astropay->callbackUrl(TransactionType::Deposit, $currency),
                'payout' => $astropay->callbackUrl(TransactionType::Payout, $currency),
            ];
        } catch (AstroPayException) {
            return ['deposit' => null, 'payout' => null];
        }
    }

    /**
     * Last four characters only, e.g. "••••••••3fa9".
     */
    private static function hint(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_repeat('•', 8).mb_substr($value, -min(4, max(0, mb_strlen($value) - 4)));
    }

    /**
     * @return list<string>
     */
    private static function parseIps(string $input): array
    {
        $parts = preg_split('/[\s,]+/', $input) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($ip) => $ip !== '')));
    }

    private static function isValidIpOrCidr(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        [$ip, $prefix] = explode('/', $value, 2);

        if (preg_match('/^\d{1,3}$/', $prefix) !== 1) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (int) $prefix <= 32 && IpUtils::checkIp($ip, $value);
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && (int) $prefix <= 128;
    }

    private static function amountOrNull(mixed $value): ?string
    {
        return filled($value) ? Amount::toRequest((string) $value) : null;
    }
}
