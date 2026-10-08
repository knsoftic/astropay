<?php

namespace Tests\Feature\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Models\AstroPayAccount;
use App\Models\User;
use App\Services\AstroPay\AstroPayManager;
use App\Services\AstroPay\AstroPaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithAstroPay;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use InteractsWithAstroPay, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Start with no saved merchant accounts.
        $this->configureAstroPay();
        AstroPayAccount::query()->delete();
        app(AstroPaySettings::class)->forget();
    }

    private function confirmedAdmin(): User
    {
        $admin = $this->admin();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

        return $admin;
    }

    private function saveAccount(string $currency, array $input): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('admin.settings.edit'))->put(route('admin.settings.account', $currency), $input + ['form' => $currency]);
    }

    private function manager(): AstroPayManager
    {
        return app(AstroPayManager::class);
    }

    public function test_settings_require_admin_and_password_confirmation(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.settings.edit'))->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true, 'password' => 'Secret#12345']);
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertRedirect(route('password.confirm'));

        $this->post(route('password.confirm'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post(route('password.confirm'), ['password' => 'Secret#12345'])->assertRedirect(route('admin.settings.edit'));

        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('API connection')->assertSee('Pakistani Rupee (PKR)');
    }

    public function test_admin_saves_keys_which_are_encrypted_and_never_shown(): void
    {
        $admin = $this->confirmedAdmin();

        $this->saveAccount('PKR', [
            'merchant_key' => 'mk_live_pkr_1234', 'secret_key' => 'sk_live_pkr_SECRET', 'enabled' => '1',
            'deposit_min' => '100', 'deposit_max' => '50000', 'payout_min' => '', 'payout_max' => '25000.50',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($this->manager()->isEnabled(Currency::PKR));
        $this->assertSame('sk_live_pkr_SECRET', $this->manager()->secretKey(Currency::PKR));
        $this->assertSame(['min' => '100.00', 'max' => '50000.00'], $this->manager()->limits(Currency::PKR, TransactionType::Deposit));
        $this->assertSame(['min' => null, 'max' => '25000.50'], $this->manager()->limits(Currency::PKR, TransactionType::Payout));

        $row = DB::table('astropay_accounts')->where('currency', 'PKR')->first();
        $this->assertNotSame('sk_live_pkr_SECRET', $row->secret_key);
        $this->assertStringNotContainsString('sk_live_pkr_SECRET', $row->secret_key);
        $this->assertSame($admin->id, (int) $row->updated_by);

        $this->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertDontSee('sk_live_pkr_SECRET')
            ->assertDontSee('mk_live_pkr_1234')
            ->assertSee('1234');
    }

    public function test_empty_key_fields_keep_the_saved_keys(): void
    {
        $this->confirmedAdmin();
        $this->saveAccount('INR', ['merchant_key' => 'mk_inr_a', 'secret_key' => 'sk_inr_a', 'enabled' => '1']);

        $this->saveAccount('INR', ['merchant_key' => '', 'secret_key' => '', 'enabled' => '1', 'deposit_min' => '200'])->assertSessionHasNoErrors();

        $this->assertSame('sk_inr_a', $this->manager()->secretKey(Currency::INR));
        $this->assertSame('200.00', $this->manager()->limits(Currency::INR, TransactionType::Deposit)['min']);

        $this->saveAccount('INR', ['secret_key' => 'sk_inr_b', 'enabled' => '1']);
        $this->assertSame('sk_inr_b', $this->manager()->secretKey(Currency::INR));
    }

    public function test_enabling_requires_both_keys_and_limits_are_validated(): void
    {
        $this->confirmedAdmin();

        $this->saveAccount('BDT', ['merchant_key' => 'mk_bdt', 'enabled' => '1'])->assertSessionHasErrorsIn('account_BDT', 'merchant_key');
        $this->saveAccount('BDT', ['merchant_key' => 'mk bdt', 'secret_key' => 'x'])->assertSessionHasErrorsIn('account_BDT', 'merchant_key');
        $this->saveAccount('BDT', ['deposit_min' => '500', 'deposit_max' => '100'])->assertSessionHasErrorsIn('account_BDT', 'deposit_max');
        $this->saveAccount('BDT', ['payout_min' => '1.234'])->assertSessionHasErrorsIn('account_BDT', 'payout_min');

        $this->assertDatabaseCount('astropay_accounts', 0);
        $this->assertFalse($this->manager()->isEnabled(Currency::BDT));
    }

    public function test_disabled_currency_refuses_new_orders_but_still_processes_callbacks(): void
    {
        $this->confirmedAdmin();
        $this->saveAccount('INR', ['merchant_key' => 'mk_inr', 'secret_key' => 'sk_inr', 'enabled' => '1']);

        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);

        $this->saveAccount('INR', ['enabled' => '0']);
        $this->assertFalse($this->manager()->isEnabled(Currency::INR));
        $this->assertTrue($this->manager()->hasCredentials(Currency::INR));

        $this->actingAs($user)->post(route('deposits.store'), [
            'currency' => 'INR', 'amount' => '500', 'phone' => '9876543210', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('currency');

        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10'))->assertOk();
        $this->assertSame(TransactionStatus::Success, $transaction->fresh()->status);
        $this->assertSame('500.0000', $this->walletBalance($user, Currency::INR));
    }

    public function test_removing_credentials_disables_the_currency(): void
    {
        $this->confirmedAdmin();
        $this->saveAccount('USDT', ['merchant_key' => 'mk_usdt', 'secret_key' => 'sk_usdt', 'enabled' => '1']);

        $this->saveAccount('USDT', ['clear_credentials' => '1', 'enabled' => '1'])->assertSessionHas('success');

        $this->assertFalse($this->manager()->hasCredentials(Currency::USDT));
        $this->assertFalse(AstroPayAccount::query()->sole()->enabled);
    }

    public function test_merchant_data_is_never_read_from_env_or_config(): void
    {
        // Even if someone puts the old .env-style values into config, they are ignored.
        config([
            'astropay.base_url' => 'https://evil.example',
            'astropay.callback_base_url' => 'https://evil.example',
            'astropay.accounts.PKR' => ['merchant_key' => 'env_mk', 'secret_key' => 'env_sk', 'deposit_min' => '10'],
            'astropay.webhooks.allowed_ips' => ['10.0.0.1'],
        ]);
        app(AstroPaySettings::class)->forget();

        $this->assertFalse($this->manager()->hasCredentials(Currency::PKR));
        $this->assertSame([], $this->manager()->configuredCurrencies());
        $this->assertSame('https://api.gpay.one', app(AstroPaySettings::class)->baseUrl());
        $this->assertSame([], $this->manager()->allowedWebhookIps());
        $this->assertStringStartsWith('https://merchant.test/', $this->manager()->callbackUrl(TransactionType::Deposit, Currency::PKR));
    }

    public function test_default_base_url_is_stored_in_the_database_and_required(): void
    {
        $this->assertDatabaseHas('astropay_settings', ['key' => AstroPaySettings::BASE_URL]);

        $this->setAccount('INR', ['merchant_key' => 'mk', 'secret_key' => 'sk', 'enabled' => true]);
        $this->setSetting(AstroPaySettings::BASE_URL, null);

        $this->expectException(\App\Exceptions\AstroPay\ConfigurationException::class);
        $this->expectExceptionMessage('base URL is not set');

        $this->manager()->client(Currency::INR);
    }

    public function test_requests_use_the_keys_saved_in_admin_settings(): void
    {
        $this->confirmedAdmin();
        $this->saveAccount('INR', ['merchant_key' => 'mk_from_admin', 'secret_key' => 'sk_from_admin', 'enabled' => '1']);

        Http::fake(['api.gpay.one/v1/payins/create' => Http::response($this->ok(['pay_url' => 'https://checkout.example/a']))]);

        $this->actingAs(User::factory()->create())->post(route('deposits.store'), [
            'currency' => 'INR', 'amount' => '500', 'phone' => '9876543210', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('https://checkout.example/a');

        Http::assertSent(fn ($r) => $r['merchantKey'] === 'mk_from_admin' && $r['secretKey'] === 'sk_from_admin');
    }

    public function test_connection_settings_are_validated_saved_and_used(): void
    {
        $this->confirmedAdmin();
        $put = fn (array $input) => $this->from(route('admin.settings.edit'))->put(route('admin.settings.general'), $input + ['form' => 'connection']);

        $put(['base_url' => 'http://api.gpay.one'])->assertSessionHasErrorsIn('general', 'base_url');
        $put(['base_url' => 'https://api.gpay.one?x=1'])->assertSessionHasErrorsIn('general', 'base_url');
        $put(['base_url' => 'https://api.gpay.one', 'webhook_allowed_ips' => "203.0.113.10\nnot-an-ip"])->assertSessionHasErrorsIn('general', 'webhook_allowed_ips');
        $put(['base_url' => 'https://api.gpay.one', 'webhook_allowed_ips' => '203.0.113.0/33'])->assertSessionHasErrorsIn('general', 'webhook_allowed_ips');

        $put([
            'base_url' => 'https://api2.gpay.one/',
            'callback_base_url' => 'https://pay.example.com/',
            'webhook_allowed_ips' => "203.0.113.10, 198.51.100.0/24\n2001:db8::/32",
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(['203.0.113.10', '198.51.100.0/24', '2001:db8::/32'], $this->manager()->allowedWebhookIps());
        $this->assertSame('https://pay.example.com/webhooks/astropay/payout/BDT', $this->manager()->callbackUrl(TransactionType::Payout, Currency::BDT));

        $this->saveAccount('BDT', ['merchant_key' => 'mk_bdt', 'secret_key' => 'sk_bdt', 'enabled' => '1']);
        Http::fake(['api2.gpay.one/v1/account/balance' => Http::response($this->ok(['MID' => 'MID9', 'Balance' => '1500.0000', 'FreezeBalance' => '0']))]);

        $this->post(route('admin.settings.test', 'BDT'))->assertSessionHas('success', 'BDT: connected. MID MID9, available balance 1,500.00, frozen 0.00.');

        // Clearing the callback override falls back to the site URL.
        $put(['base_url' => 'https://api.gpay.one', 'callback_base_url' => '', 'webhook_allowed_ips' => '']);
        $this->assertSame([], $this->manager()->allowedWebhookIps());
        $this->assertStringEndsWith('/webhooks/astropay/payout/BDT', $this->manager()->callbackUrl(TransactionType::Payout, Currency::BDT));
        $this->assertStringStartsNotWith('https://pay.example.com', $this->manager()->callbackUrl(TransactionType::Payout, Currency::BDT));
    }

    public function test_connection_test_explains_common_failures(): void
    {
        $this->confirmedAdmin();
        $this->saveAccount('PKR', ['merchant_key' => 'mk', 'secret_key' => 'sk', 'enabled' => '1']);

        Http::fake(['api.gpay.one/v1/account/balance' => Http::response($this->error(403, 'ip not allowed'), 403)]);

        $this->post(route('admin.settings.test', 'PKR'))->assertSessionHas('error', fn ($m) => str_contains($m, 'not whitelisted'));
    }

    public function test_changed_app_key_is_reported_instead_of_crashing(): void
    {
        $this->confirmedAdmin();
        $this->saveAccount('INR', ['merchant_key' => 'mk_inr', 'secret_key' => 'sk_inr', 'enabled' => '1']);

        DB::table('astropay_accounts')->where('currency', 'INR')->update(['secret_key' => 'eyJpdiI6ImJyb2tlbiJ9']);
        app(\App\Services\AstroPay\AstroPaySettings::class)->forget();

        $this->assertFalse($this->manager()->hasCredentials(Currency::INR));
        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('cannot be decrypted');
    }
}
