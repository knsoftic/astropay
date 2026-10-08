<?php

namespace Tests\Feature\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use App\Enums\AstroPay\TransactionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithAstroPay;
use Tests\TestCase;

class AccessAndToolsTest extends TestCase
{
    use InteractsWithAstroPay, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAstroPay();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->post(route('deposits.store'))->assertRedirect(route('login'));
    }

    public function test_users_only_see_their_own_transactions(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $owner->id]);

        $this->actingAs($owner)->get(route('transactions.show', $transaction))->assertOk()->assertSee($transaction->order_id);
        $this->actingAs($other)->get(route('transactions.show', $transaction))->assertForbidden();
        $this->actingAs($other)->post(route('transactions.check', $transaction))->assertForbidden();
        $this->actingAs($other)->get(route('transactions.pay', $transaction))->assertForbidden();
    }

    public function test_admin_area_requires_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.transactions.index'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->assertSee('Merchant accounts');
    }

    public function test_pages_render(): void
    {
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::PKR, '1500');
        $deposit = $this->makeTransaction(['user_id' => $user->id]);
        $payout = $this->makeTransaction([
            'user_id' => $user->id, 'type' => \App\Enums\AstroPay\TransactionType::Payout, 'currency' => Currency::PKR,
            'payment_method' => PaymentMethod::JAZZCASH, 'status' => TransactionStatus::AwaitingApproval,
            'beneficiary_account' => '03001234567', 'beneficiary_name' => 'Ali Khan', 'submitted_at' => null,
        ]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('1,500.00');
        $this->actingAs($user)->get(route('withdrawals.create'))->assertOk()->assertSee('Request withdrawal');
        $this->actingAs($user)->get(route('transactions.index'))->assertOk();
        $this->actingAs($user)->get(route('transactions.show', $payout))->assertOk()->assertSee('*******4567')->assertDontSee('03001234567');

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.transactions.index', ['review' => 1, 'type' => 'deposit']))->assertOk();
        $this->actingAs($admin)->get(route('admin.transactions.show', $payout))->assertOk()->assertSee('Approve and send')->assertSee('03001234567');
        $this->actingAs($admin)->get(route('admin.transactions.show', $deposit))->assertOk();
        $this->actingAs($admin)->get(route('admin.utr.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.webhooks.index'))->assertOk();
    }

    public function test_admin_balance_lookup(): void
    {
        Http::fake(['api.gpay.one/v1/account/balance' => Http::response($this->ok([
            'MID' => 'MID001', 'Currency' => 1, 'Balance' => '997479.0000', 'FreezeBalance' => '11060.0000',
        ]))]);

        $this->actingAs($this->admin())->get(route('admin.balance', 'INR'))->assertOk()->assertSee('997479.0000');

        Http::assertSent(fn ($r) => $r->url() === 'https://api.gpay.one/v1/account/balance' && $r['merchantKey'] === 'mk_inr');
    }

    public function test_customer_can_submit_a_utr_for_an_inr_deposit(): void
    {
        Http::fake(['api.gpay.one/v1/utr/supplement' => Http::response($this->ok(['accepted' => true]))]);
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);

        $this->actingAs($user)->post(route('transactions.utr', $transaction), ['utr' => '4375 5823 1943'])->assertSessionHas('success');

        $this->assertSame('437558231943', $transaction->fresh()->supplemented_utr);
        Http::assertSent(fn ($r) => $r['orderId'] === $transaction->order_id && $r['utr'] === '437558231943' && $r['merchantKey'] === 'mk_inr');
    }

    public function test_utr_tools_are_inr_only(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id, 'currency' => Currency::PKR, 'payment_method' => PaymentMethod::EASYPAISA]);

        $this->actingAs($user)->post(route('transactions.utr', $transaction), ['utr' => '437558231943'])->assertSessionHas('error');
        $this->actingAs($this->admin())->post(route('admin.utr.supplement'), ['order_id' => $transaction->order_id, 'utr' => '437558231943'])->assertSessionHasErrors('order_id');

        Http::assertNothingSent();
    }

    public function test_admin_utr_query(): void
    {
        Http::fake(['api.gpay.one/v1/utr/query' => Http::response($this->ok(['utr' => '437558231943', 'amount' => '500.00']))]);

        $this->actingAs($this->admin())->post(route('admin.utr.query'), ['utr' => '437558231943'])
            ->assertSessionHas('utr_result', fn ($result) => $result['data']['amount'] === '500.00');
    }

    public function test_register_and_login(): void
    {
        $this->post(route('register'), [
            'name' => 'New User', 'email' => 'new@example.com', 'password' => 'Secret#12345', 'password_confirmation' => 'Secret#12345',
        ])->assertRedirect(route('dashboard'));

        $this->post(route('logout'));

        $this->post(route('login'), ['email' => 'new@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'new@example.com', 'password' => 'Secret#12345'])->assertRedirect(route('dashboard'));
    }

    public function test_make_admin_command_promotes_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('app:make-admin', ['email' => 'boss@example.com'])->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_balance_command(): void
    {
        Http::fake(['api.gpay.one/v1/account/balance' => Http::response($this->ok(['Balance' => '10.0000']))]);
        foreach (['PKR', 'BDT', 'USDT'] as $currency) {
            $this->setAccount($currency, ['merchant_key' => null]);
        }

        $this->artisan('astropay:balance')->expectsOutputToContain('10.0000')->assertSuccessful();
        $this->artisan('astropay:balance', ['currency' => 'XYZ'])->assertExitCode(2);
    }
}
