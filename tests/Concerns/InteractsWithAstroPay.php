<?php

namespace Tests\Concerns;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Enums\WalletEntryType;
use App\Models\AstroPayAccount;
use App\Models\AstroPayTransaction;
use App\Services\AstroPay\AstroPaySettings;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AstroPay\Support\OrderId;
use App\Services\AstroPay\Support\Signature;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

trait InteractsWithAstroPay
{
    /**
     * Merchant data lives in the database (Admin → Settings), behaviour in config.
     */
    protected function configureAstroPay(array $overrides = []): void
    {
        foreach (Currency::cases() as $currency) {
            $code = strtolower($currency->value);
            $this->setAccount($currency, ['merchant_key' => "mk_{$code}", 'secret_key' => "sk_{$code}", 'enabled' => true]);
        }

        $this->setSetting(AstroPaySettings::BASE_URL, 'https://api.gpay.one');
        $this->setSetting(AstroPaySettings::CALLBACK_BASE_URL, 'https://merchant.test');

        config(array_merge([
            'astropay.payouts.require_approval' => true,
            'astropay.webhooks.confirm_via_query' => false,
            'astropay.deposits.wallet_credit' => 'gross',
            'astropay.status_check.cooldown_seconds' => 0,
            'astropay.status_check.not_found_grace_minutes' => 15,
            'astropay.log_channel' => 'null',
        ], $overrides));

        Sleep::fake();

        // Any AstroPay call a test did not stub explicitly fails loudly.
        Http::preventStrayRequests();
    }

    /**
     * Create or update the stored account of a currency.
     */
    protected function setAccount(Currency|string $currency, array $values): AstroPayAccount
    {
        $currency = $currency instanceof Currency ? $currency : Currency::from($currency);

        $account = AstroPayAccount::query()->firstOrNew(['currency' => $currency->value]);
        $account->fill($values)->save();

        return $account;
    }

    /**
     * Store (or, with an empty value, delete) a connection setting.
     */
    protected function setSetting(string $key, mixed $value): void
    {
        app(AstroPaySettings::class)->saveGeneral([$key => $value], null);
    }

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    protected function fundWallet(User $user, Currency $currency, string $amount): Wallet
    {
        return DB::transaction(function () use ($user, $currency, $amount) {
            $wallets = app(WalletService::class);
            $wallet = $wallets->lockedWallet($user->id, $currency);
            $wallets->credit($wallet, $amount, WalletEntryType::DepositCredit, null, 'Test funding');

            return $wallet->refresh();
        });
    }

    protected function walletBalance(User $user, Currency $currency): string
    {
        return app(WalletService::class)->balance($user->id, $currency);
    }

    protected function makeTransaction(array $attributes = []): AstroPayTransaction
    {
        $type = $attributes['type'] ?? TransactionType::Deposit;

        return AstroPayTransaction::query()->create(array_merge([
            'user_id' => null,
            'type' => $type,
            'currency' => Currency::INR,
            'payment_method' => PaymentMethod::UPI,
            'order_id' => OrderId::generate($type),
            'attempts' => 1,
            'amount' => '500.0000',
            'status' => TransactionStatus::Pending,
            'gateway_status' => 1,
            'submitted_at' => now(),
        ], $attributes));
    }

    /**
     * A signed callback body, as AstroPay would send it.
     *
     * @return array<string, string>
     */
    protected function signedCallback(AstroPayTransaction $transaction, string $status, array $overrides = [], ?string $secret = null): array
    {
        $payload = array_merge([
            'orderId' => $transaction->order_id,
            'amount' => $transaction->amount,
            'commission' => '35.0000',
            'status' => $status,
            'utr' => '437558231943',
        ], $overrides);

        $secret ??= 'sk_'.strtolower($transaction->currency->value);
        $payload['sign'] = Signature::forCallback($payload, $secret);

        return $payload;
    }

    protected function webhookUrl(AstroPayTransaction $transaction): string
    {
        return "/webhooks/astropay/{$transaction->type->value}/{$transaction->currency->value}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function ok(array $data = []): array
    {
        return ['code' => 1000, 'data' => $data, 'msg' => 'success'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function error(int $code, string $message): array
    {
        return ['code' => $code, 'data' => null, 'msg' => $message];
    }
}
