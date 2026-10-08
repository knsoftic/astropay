<?php

namespace Tests\Feature\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionStatus;
use App\Models\AstroPayTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithAstroPay;
use Tests\TestCase;

class DepositTest extends TestCase
{
    use InteractsWithAstroPay, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAstroPay();
    }

    private function deposit(User $user, array $input): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->from(route('deposits.create'))->post(route('deposits.store'), $input + [
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_deposit_is_created_and_customer_redirected_to_hosted_checkout(): void
    {
        Http::fake(['api.gpay.one/v1/payins/create' => function (Request $request) {
            return Http::response($this->ok(['pay_url' => 'https://checkout.example/tok123', 'order_id' => $request['orderId']]));
        }]);

        $user = User::factory()->create(['name' => 'Asha Rao', 'email' => 'asha@example.com']);

        $response = $this->deposit($user, ['currency' => 'INR', 'payment_method' => 'UPI', 'amount' => '500', 'phone' => '+91 98765 43210']);

        $response->assertRedirect('https://checkout.example/tok123');

        $transaction = AstroPayTransaction::query()->sole();
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertSame('500.0000', $transaction->amount);
        $this->assertSame('https://checkout.example/tok123', $transaction->pay_url);

        Http::assertSent(function (Request $request) use ($transaction) {
            return $request->url() === 'https://api.gpay.one/v1/payins/create'
                && $request->method() === 'POST'
                && $request->isJson()
                && $request['merchantKey'] === 'mk_inr'
                && $request['secretKey'] === 'sk_inr'
                && $request['orderId'] === $transaction->order_id
                && $request['amount'] === '500.00'
                && $request['callbackUrl'] === 'https://merchant.test/webhooks/astropay/deposit/INR'
                && $request['name'] === 'Asha Rao'
                && $request['phone'] === '9876543210'
                && $request['email'] === 'asha@example.com'
                && $request['channel'] === 'UPI';
        });
    }

    public function test_channel_is_omitted_when_automatic_and_for_usdt(): void
    {
        Http::fake(['api.gpay.one/v1/payins/create' => Http::response($this->ok(['pay_url' => 'https://checkout.example/x']))]);

        $user = User::factory()->create();

        $this->deposit($user, ['currency' => 'PKR', 'payment_method' => '', 'amount' => '1000', 'phone' => '03001234567'])->assertRedirect('https://checkout.example/x');
        $this->deposit($user, ['currency' => 'USDT', 'amount' => '25.50', 'phone' => '+1 555 123 4567'])->assertRedirect('https://checkout.example/x');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => ! array_key_exists('channel', $r->data()));
        Http::assertSent(fn (Request $r) => $r['merchantKey'] === 'mk_usdt' && $r['amount'] === '25.50');
    }

    public function test_payment_method_must_match_currency(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->deposit($user, ['currency' => 'PKR', 'payment_method' => 'BKASH', 'amount' => '100', 'phone' => '03001234567'])
            ->assertSessionHasErrors('payment_method');
        $this->deposit($user, ['currency' => 'USDT', 'payment_method' => 'UPI', 'amount' => '100', 'phone' => '15551234567'])
            ->assertSessionHasErrors('payment_method');

        Http::assertNothingSent();
        $this->assertDatabaseCount('astropay_transactions', 0);
    }

    public function test_amount_and_phone_are_validated(): void
    {
        Http::fake();
        $this->setAccount('BDT', ['deposit_min' => '200', 'deposit_max' => '25000']);
        $user = User::factory()->create();

        $this->deposit($user, ['currency' => 'BDT', 'amount' => '10.123', 'phone' => '01712345678'])->assertSessionHasErrors('amount');
        $this->deposit($user, ['currency' => 'BDT', 'amount' => '0', 'phone' => '01712345678'])->assertSessionHasErrors('amount');
        $this->deposit($user, ['currency' => 'BDT', 'amount' => '-5', 'phone' => '01712345678'])->assertSessionHasErrors('amount');
        $this->deposit($user, ['currency' => 'BDT', 'amount' => '199.99', 'phone' => '01712345678'])->assertSessionHasErrors('amount');
        $this->deposit($user, ['currency' => 'BDT', 'amount' => '25000.01', 'phone' => '01712345678'])->assertSessionHasErrors('amount');
        $this->deposit($user, ['currency' => 'BDT', 'amount' => '500', 'phone' => '03001234567'])->assertSessionHasErrors('phone');

        Http::assertNothingSent();
    }

    public function test_disabled_currency_is_refused(): void
    {
        Http::fake();
        $this->setAccount('BDT', ['merchant_key' => null]);

        $this->deposit(User::factory()->create(), ['currency' => 'BDT', 'amount' => '500', 'phone' => '01712345678'])
            ->assertSessionHasErrors('currency');

        Http::assertNothingSent();
    }

    public function test_gateway_validation_error_is_shown_and_order_rejected(): void
    {
        Http::fake(['api.gpay.one/v1/payins/create' => Http::response($this->error(400, 'Deposit amount must not be lower than: 100'), 400)]);

        $response = $this->deposit(User::factory()->create(), ['currency' => 'INR', 'amount' => '50', 'phone' => '9876543210']);

        $response->assertRedirect(route('deposits.create'));
        $response->assertSessionHas('error', 'Deposit amount must not be lower than: 100');

        $transaction = AstroPayTransaction::query()->sole();
        $this->assertSame(TransactionStatus::Rejected, $transaction->status);
        $this->assertSame(400, $transaction->error_code);
    }

    public function test_chinese_internal_errors_are_not_shown_to_customers(): void
    {
        Http::fake(['api.gpay.one/v1/payins/create' => Http::response($this->error(400, '通道维护中'), 400)]);

        $this->deposit(User::factory()->create(), ['currency' => 'INR', 'amount' => '500', 'phone' => '9876543210'])
            ->assertSessionHas('error', 'The payment provider could not process this request.');
    }

    public function test_timeout_leaves_the_order_unknown_and_is_not_retried(): void
    {
        Http::fake(['api.gpay.one/v1/payins/create' => Http::failedConnection('cURL error 28: timed out')]);

        $response = $this->deposit(User::factory()->create(), ['currency' => 'INR', 'amount' => '500', 'phone' => '9876543210']);

        $transaction = AstroPayTransaction::query()->sole();
        $response->assertRedirect(route('transactions.show', $transaction));
        $response->assertSessionHas('warning');
        $this->assertSame(TransactionStatus::Unknown, $transaction->status);
        Http::assertSentCount(1);
    }

    public function test_server_error_on_create_is_treated_as_unknown(): void
    {
        Http::fake(['api.gpay.one/v1/payins/create' => Http::response($this->error(500, 'upstream gateway error'), 500)]);

        $this->deposit(User::factory()->create(), ['currency' => 'INR', 'amount' => '500', 'phone' => '9876543210']);

        $this->assertSame(TransactionStatus::Unknown, AstroPayTransaction::query()->sole()->status);
        Http::assertSentCount(1);
    }

    public function test_non_json_response_is_treated_as_unknown(): void
    {
        Http::fake(['api.gpay.one/v1/payins/create' => Http::response('<html>502 Bad Gateway</html>', 502)]);

        $this->deposit(User::factory()->create(), ['currency' => 'INR', 'amount' => '500', 'phone' => '9876543210']);

        $this->assertSame(TransactionStatus::Unknown, AstroPayTransaction::query()->sole()->status);
    }

    public function test_double_submitted_form_creates_one_order(): void
    {
        Http::fake(['api.gpay.one/v1/payins/create' => Http::response($this->ok(['pay_url' => 'https://checkout.example/once']))]);
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $input = ['currency' => 'INR', 'amount' => '500', 'phone' => '9876543210', 'idempotency_key' => $key];

        $this->actingAs($user)->post(route('deposits.store'), $input)->assertRedirect('https://checkout.example/once');
        $this->actingAs($user)->post(route('deposits.store'), $input)->assertRedirect('https://checkout.example/once');

        $this->assertDatabaseCount('astropay_transactions', 1);
        Http::assertSentCount(1);
    }

    public function test_missing_pay_url_is_reported(): void
    {
        Http::fake(['api.gpay.one/v1/payins/create' => Http::response($this->ok(['order_id' => 'x']))]);

        $response = $this->deposit(User::factory()->create(), ['currency' => 'INR', 'amount' => '500', 'phone' => '9876543210']);

        $transaction = AstroPayTransaction::query()->sole();
        $response->assertRedirect(route('transactions.show', $transaction));
        $response->assertSessionHas('error');
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertNull($transaction->pay_url);
    }

    public function test_deposit_form_renders_for_enabled_currencies(): void
    {
        $this->setAccount('BDT', ['enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('deposits.create'))
            ->assertOk()
            ->assertSee('Indian Rupee (INR)')
            ->assertSee('Tether (USDT, TRC20)')
            ->assertDontSee('Bangladeshi Taka (BDT)');
    }

    public function test_continue_payment_link_only_while_valid(): void
    {
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id, 'pay_url' => 'https://checkout.example/abc']);

        $this->actingAs($user)->get(route('transactions.pay', $transaction))->assertRedirect('https://checkout.example/abc');

        $transaction->forceFill(['submitted_at' => now()->subHours(2)])->save();

        $this->actingAs($user)->get(route('transactions.pay', $transaction))
            ->assertRedirect(route('transactions.show', $transaction))
            ->assertSessionHas('error');
    }

    public function test_deposit_currency_enum_round_trips(): void
    {
        $transaction = $this->makeTransaction(['currency' => Currency::BDT, 'payment_method' => null]);

        $this->assertSame(Currency::BDT, $transaction->fresh()->currency);
    }
}
