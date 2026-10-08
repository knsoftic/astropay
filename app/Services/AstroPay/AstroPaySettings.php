<?php

namespace App\Services\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Models\AstroPayAccount;
use App\Models\AstroPaySetting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * AstroPay merchant data, read only from the database (edited in Admin →
 * Settings). Nothing here comes from .env:
 *
 *  - astropay_accounts: merchant key, secret key, enabled flag and limits per currency
 *  - astropay_settings: API base URL, callback base URL, callback IP allowlist
 *
 * A currency without a row in astropay_accounts is not configured.
 */
final class AstroPaySettings
{
    public const BASE_URL = 'base_url';

    public const CALLBACK_BASE_URL = 'callback_base_url';

    public const WEBHOOK_ALLOWED_IPS = 'webhook_allowed_ips';

    /** @var array<string, AstroPayAccount>|null */
    private ?array $accounts = null;

    /** @var array<string, mixed>|null */
    private ?array $general = null;

    /**
     * Settings for one currency account.
     *
     * @return array{merchant_key: string|null, secret_key: string|null, enabled: bool, deposit_min: string|null, deposit_max: string|null, payout_min: string|null, payout_max: string|null, decrypt_failed: bool, updated_at: \Illuminate\Support\Carbon|null, updated_by: int|null}
     */
    public function account(Currency $currency): array
    {
        $row = $this->accounts()[$currency->value] ?? null;

        if ($row === null) {
            return [
                'merchant_key' => null,
                'secret_key' => null,
                'enabled' => false,
                'deposit_min' => null,
                'deposit_max' => null,
                'payout_min' => null,
                'payout_max' => null,
                'decrypt_failed' => false,
                'updated_at' => null,
                'updated_by' => null,
            ];
        }

        $decryptFailed = false;

        try {
            $merchantKey = $row->merchant_key;
            $secretKey = $row->secret_key;
        } catch (DecryptException) {
            // APP_KEY changed since the keys were saved; they must be entered again.
            $merchantKey = $secretKey = null;
            $decryptFailed = true;
        }

        return [
            'merchant_key' => self::clean($merchantKey),
            'secret_key' => self::clean($secretKey),
            'enabled' => $row->enabled && ! $decryptFailed,
            'deposit_min' => self::clean($row->deposit_min),
            'deposit_max' => self::clean($row->deposit_max),
            'payout_min' => self::clean($row->payout_min),
            'payout_max' => self::clean($row->payout_max),
            'decrypt_failed' => $decryptFailed,
            'updated_at' => $row->updated_at,
            'updated_by' => $row->updated_by,
        ];
    }

    /**
     * API base URL, or null when it has not been saved in Admin → Settings.
     */
    public function baseUrl(): ?string
    {
        return self::clean($this->stored(self::BASE_URL));
    }

    /**
     * Public base URL for callbacks, or null to use the site's own URL.
     */
    public function callbackBaseUrl(): ?string
    {
        return self::clean($this->stored(self::CALLBACK_BASE_URL));
    }

    /**
     * @return list<string>
     */
    public function webhookAllowedIps(): array
    {
        $stored = $this->stored(self::WEBHOOK_ALLOWED_IPS);

        return is_array($stored)
            ? array_values(array_filter($stored, fn ($ip) => is_string($ip) && $ip !== ''))
            : [];
    }

    /**
     * Create or update the account row for a currency.
     *
     * @param  array{merchant_key: string|null, secret_key: string|null, enabled: bool, deposit_min: string|null, deposit_max: string|null, payout_min: string|null, payout_max: string|null}  $values
     */
    public function saveAccount(Currency $currency, array $values, ?User $by): AstroPayAccount
    {
        return DB::transaction(function () use ($currency, $values, $by) {
            $account = AstroPayAccount::query()->lockForUpdate()->firstOrNew(['currency' => $currency->value]);
            $account->fill($values);
            $account->updated_by = $by?->getKey();
            $account->save();

            return $account;
        });
    }

    /**
     * Store connection settings. A null or empty value deletes the setting.
     *
     * @param  array<string, mixed>  $values
     */
    public function saveGeneral(array $values, ?User $by): void
    {
        DB::transaction(function () use ($values, $by) {
            foreach ($values as $key => $value) {
                if ($value === null || $value === '' || $value === []) {
                    AstroPaySetting::query()->where('key', $key)->delete();
                    $this->forget();

                    continue;
                }

                AstroPaySetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->getKey()]);
            }
        });
    }

    /**
     * Drop the in-memory copy (called whenever a settings row changes).
     */
    public function forget(): void
    {
        $this->accounts = null;
        $this->general = null;
    }

    /**
     * @return array<string, AstroPayAccount>
     */
    private function accounts(): array
    {
        if ($this->accounts === null) {
            try {
                $this->accounts = AstroPayAccount::query()->get()->keyBy(fn (AstroPayAccount $a) => $a->currency->value)->all();
            } catch (QueryException) {
                // Settings tables not migrated yet: nothing is configured.
                $this->accounts = [];
            }
        }

        return $this->accounts;
    }

    private function stored(string $key): mixed
    {
        if ($this->general === null) {
            try {
                $this->general = AstroPaySetting::query()->pluck('value', 'key')->all();
            } catch (QueryException) {
                $this->general = [];
            }
        }

        $value = $this->general[$key] ?? null;

        return ($value === '' || $value === []) ? null : $value;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
