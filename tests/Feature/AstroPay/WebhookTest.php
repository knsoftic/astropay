<?php

namespace Tests\Feature\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Enums\WalletEntryType;
use App\Events\AstroPay\DepositSucceeded;
use App\Events\AstroPay\PayoutFailed;
use App\Events\AstroPay\TransactionFlaggedForReview;
use App\Models\AstroPayWebhookLog;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\AstroPay\Support\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithAstroPay;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use InteractsWithAstroPay, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAstroPay();
    }

    public function test_successful_deposit_callback_credits_the_wallet_once(): void
    {
        Event::fake([DepositSucceeded::class]);
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);

        $payload = $this->signedCallback($transaction, '10');

        $this->postJson($this->webhookUrl($transaction), $payload)->assertOk()->assertSee('success');
        $this->postJson($this->webhookUrl($transaction), $payload)->assertOk(); // duplicate delivery

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertSame('437558231943', $transaction->utr);
        $this->assertSame('35.0000', $transaction->commission);
        $this->assertNotNull($transaction->completed_at);
        $this->assertSame('500.0000', $this->walletBalance($user, Currency::INR));
        $this->assertSame(1, WalletEntry::query()->where('transaction_id', $transaction->id)->count());
        Event::assertDispatchedTimes(DepositSucceeded::class, 1);

        $this->assertSame(['processed', 'duplicate'], AstroPayWebhookLog::query()->orderBy('id')->pluck('outcome')->all());
    }

    public function test_net_crediting_deducts_the_commission(): void
    {
        config(['astropay.deposits.wallet_credit' => 'net']);
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);

        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10'))->assertOk();

        $this->assertSame('465.0000', $this->walletBalance($user, Currency::INR));
    }

    public function test_invalid_signature_is_rejected_and_nothing_changes(): void
    {
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);

        $forged = $this->signedCallback($transaction, '10', [], 'wrong-secret');

        $this->postJson($this->webhookUrl($transaction), $forged)->assertStatus(401);

        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
        $this->assertSame('0.0000', $this->walletBalance($user, Currency::INR));
        $this->assertFalse(AstroPayWebhookLog::query()->sole()->signature_valid);
    }

    public function test_tampered_amount_is_rejected(): void
    {
        $transaction = $this->makeTransaction();
        $payload = $this->signedCallback($transaction, '10');
        $payload['amount'] = '5000.0000';

        $this->postJson($this->webhookUrl($transaction), $payload)->assertStatus(401);
    }

    public function test_json_numbers_and_form_encoded_bodies_are_both_accepted(): void
    {
        $first = $this->makeTransaction(['amount' => '500.0000']);
        $sign = Signature::forCallback(['orderId' => $first->order_id, 'amount' => '500.0000', 'commission' => '35', 'status' => '10', 'utr' => '111122223333'], 'sk_inr');
        $raw = sprintf('{"orderId":"%s","amount":500.0000,"commission":35,"status":10,"utr":"111122223333","sign":"%s"}', $first->order_id, $sign);

        $this->call('POST', $this->webhookUrl($first), [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertOk();
        $this->assertSame(TransactionStatus::Success, $first->fresh()->status);

        $second = $this->makeTransaction();
        $this->post($this->webhookUrl($second), $this->signedCallback($second, '9'))->assertOk();
        $this->assertSame(TransactionStatus::Failed, $second->fresh()->status);
    }

    public function test_amount_mismatch_flags_review_without_crediting(): void
    {
        Event::fake([TransactionFlaggedForReview::class]);
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);

        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10', ['amount' => '450.0000']))->assertOk();

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertTrue($transaction->needs_review);
        $this->assertSame('450.0000', $transaction->settled_amount);
        $this->assertSame('0.0000', $this->walletBalance($user, Currency::INR));
        Event::assertDispatched(TransactionFlaggedForReview::class);
    }

    public function test_conflicting_final_status_is_flagged_not_applied(): void
    {
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);

        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10'))->assertOk();
        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '9'))->assertOk();

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertTrue($transaction->needs_review);
        $this->assertSame('500.0000', $this->walletBalance($user, Currency::INR));
        $this->assertSame('conflict', AstroPayWebhookLog::query()->latest('id')->first()->outcome);
    }

    public function test_failed_payout_callback_refunds_the_wallet_and_stores_the_reason(): void
    {
        Event::fake([PayoutFailed::class]);
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::PKR, '1000');

        $transaction = app(\App\Services\AstroPay\PayoutService::class)->request($user, Currency::PKR, '600', PaymentMethod::JAZZCASH, [
            'account' => '03001234567', 'person_name' => 'Ali Khan',
        ]);
        $transaction->forceFill(['status' => TransactionStatus::Pending, 'submitted_at' => now()])->save();
        $this->assertSame('400.0000', $this->walletBalance($user, Currency::PKR));

        $payload = $this->signedCallback($transaction, '9', ['commission' => '14.0000', 'utr' => '', 'remark' => 'Account blocked']);
        $this->postJson($this->webhookUrl($transaction), $payload)->assertOk();
        $this->postJson($this->webhookUrl($transaction), $payload)->assertOk();

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Failed, $transaction->status);
        $this->assertSame('Account blocked', $transaction->remark);
        $this->assertSame('1000.0000', $this->walletBalance($user, Currency::PKR));
        $this->assertSame(1, WalletEntry::query()->where('transaction_id', $transaction->id)->where('type', WalletEntryType::PayoutRefund->value)->count());
        Event::assertDispatchedTimes(PayoutFailed::class, 1);
    }

    public function test_successful_payout_callback_keeps_the_debit(): void
    {
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::BDT, '1000');
        $transaction = app(\App\Services\AstroPay\PayoutService::class)->request($user, Currency::BDT, '300', PaymentMethod::BKASH, [
            'account' => '01712345678', 'person_name' => 'Rahim Uddin',
        ]);
        $transaction->forceFill(['status' => TransactionStatus::Pending, 'submitted_at' => now()])->save();

        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10', ['commission' => '6.0000', 'remark' => '']))->assertOk();

        $this->assertSame(TransactionStatus::Success, $transaction->fresh()->status);
        $this->assertSame('700.0000', $this->walletBalance($user, Currency::BDT));
    }

    public function test_unknown_order_returns_404(): void
    {
        $transaction = $this->makeTransaction();
        $payload = $this->signedCallback($transaction, '10', ['orderId' => 'GPDNOTEXISTING']);

        $this->postJson($this->webhookUrl($transaction), $payload)->assertNotFound();
        $this->assertSame('unknown_order', AstroPayWebhookLog::query()->sole()->outcome);
    }

    public function test_callback_on_the_wrong_currency_url_is_rejected(): void
    {
        $transaction = $this->makeTransaction(['currency' => Currency::PKR, 'payment_method' => PaymentMethod::EASYPAISA]);
        $payload = $this->signedCallback($transaction, '10', [], 'sk_inr');

        $this->postJson('/webhooks/astropay/deposit/INR', $payload)->assertStatus(400);
        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
    }

    public function test_callback_for_a_payout_on_the_deposit_url_is_rejected(): void
    {
        $transaction = $this->makeTransaction(['type' => TransactionType::Payout]);

        $this->postJson('/webhooks/astropay/deposit/INR', $this->signedCallback($transaction, '10'))->assertStatus(400);
    }

    public function test_missing_fields_and_bad_status_are_rejected(): void
    {
        $transaction = $this->makeTransaction();

        $this->postJson($this->webhookUrl($transaction), ['orderId' => $transaction->order_id, 'status' => '10'])->assertStatus(400);
        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '7'))->assertStatus(400);
        $this->call('POST', $this->webhookUrl($transaction), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"orderId":')->assertStatus(400);
    }

    public function test_disabled_currency_and_ip_allowlist(): void
    {
        $transaction = $this->makeTransaction();
        $payload = $this->signedCallback($transaction, '10');

        $this->setSetting(\App\Services\AstroPay\AstroPaySettings::WEBHOOK_ALLOWED_IPS, ['203.0.113.0/24']);
        $this->postJson($this->webhookUrl($transaction), $payload)->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson($this->webhookUrl($transaction), $payload)->assertOk();

        $this->setSetting(\App\Services\AstroPay\AstroPaySettings::WEBHOOK_ALLOWED_IPS, []);
        $this->setAccount('INR', ['secret_key' => null]);
        $this->postJson($this->webhookUrl($transaction), $payload)->assertNotFound();
    }

    public function test_webhook_route_needs_no_csrf_token_or_session(): void
    {
        $transaction = $this->makeTransaction();

        $response = $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10'));

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_confirmation_query_applies_the_queried_status(): void
    {
        config(['astropay.webhooks.confirm_via_query' => true]);
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);

        Http::fake(['api.gpay.one/v1/payins/query' => Http::response($this->ok([
            'orderId' => $transaction->order_id, 'amount' => '500.0000', 'commission' => '35.0000', 'status' => 10,
            'createTime' => '2026-09-06 14:02:11', 'updateTime' => '2026-09-06 14:04:37', 'is_test' => 1,
        ]))]);

        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10'))->assertOk();

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertSame('437558231943', $transaction->utr, 'UTR is taken from the signed callback');
        $this->assertTrue($transaction->is_test);
        $this->assertSame('2026-09-06 14:04:37', $transaction->gateway_update_time);
        $this->assertSame('500.0000', $this->walletBalance($user, Currency::INR));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.gpay.one/v1/payins/query' && $request['orderId'] === $transaction->order_id);
    }

    public function test_unconfirmed_callback_is_recorded_but_not_applied(): void
    {
        config(['astropay.webhooks.confirm_via_query' => true]);
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);

        Http::fake(['api.gpay.one/v1/payins/query' => Http::response($this->ok([
            'orderId' => $transaction->order_id, 'amount' => '500.0000', 'commission' => '0', 'status' => 1,
        ]))]);

        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10'))->assertOk();

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertNotNull($transaction->callback_received_at);
        $this->assertSame('10', $transaction->last_callback['status']);
        $this->assertArrayNotHasKey('sign', $transaction->last_callback);
        $this->assertSame('0.0000', $this->walletBalance($user, Currency::INR));
        $this->assertSame('unconfirmed', AstroPayWebhookLog::query()->sole()->outcome);
    }

    public function test_confirmation_query_failure_still_answers_2xx(): void
    {
        config(['astropay.webhooks.confirm_via_query' => true]);
        $transaction = $this->makeTransaction();
        Http::fake(['api.gpay.one/v1/payins/query' => Http::failedConnection()]);

        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10'))->assertOk();

        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
        $this->assertSame('unconfirmed', AstroPayWebhookLog::query()->sole()->outcome);
    }

    public function test_callback_arriving_before_the_create_response_is_kept(): void
    {
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id, 'status' => TransactionStatus::Initiated, 'gateway_status' => null]);

        $this->postJson($this->webhookUrl($transaction), $this->signedCallback($transaction, '10'))->assertOk();

        $processor = app(\App\Services\AstroPay\TransactionProcessor::class);
        $processor->markSubmitted($transaction, new \App\Services\AstroPay\GatewayResponse(['pay_url' => 'https://checkout.example/late'], 'success', 200, []));

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertSame('https://checkout.example/late', $transaction->pay_url);
        $this->assertSame('500.0000', $this->walletBalance($user, Currency::INR));
    }
}
