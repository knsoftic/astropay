<?php

namespace Tests\Feature\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use App\Models\AstroPayTransaction;
use App\Models\User;
use App\Services\AstroPay\PayoutService;
use App\Services\AstroPay\TransactionProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithAstroPay;
use Tests\TestCase;

class StatusCheckTest extends TestCase
{
    use InteractsWithAstroPay, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAstroPay();
    }

    private function heldPayout(User $user, string $status = 'unknown', ?\DateTimeInterface $submittedAt = null): AstroPayTransaction
    {
        $this->fundWallet($user, Currency::PKR, '1000');
        $transaction = app(PayoutService::class)->request($user, Currency::PKR, '400', PaymentMethod::EASYPAISA, ['account' => '03451234567', 'person_name' => 'Sara Ahmed']);
        $transaction->forceFill(['status' => $status, 'attempts' => 1, 'submitted_at' => $submittedAt ?? now()])->save();

        return $transaction->fresh();
    }

    public function test_user_status_check_settles_a_missed_deposit_callback(): void
    {
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);
        Http::fake(['api.gpay.one/v1/payins/query' => Http::response($this->ok([
            'orderId' => $transaction->order_id, 'amount' => '500.0000', 'commission' => '35.0000', 'status' => 10,
            'createTime' => '2026-09-06 14:02:11', 'updateTime' => '2026-09-06 14:04:37', 'is_test' => 0,
        ]))]);

        $this->actingAs($user)->from(route('transactions.show', $transaction))
            ->post(route('transactions.check', $transaction))
            ->assertSessionHas('success');

        $this->assertSame(TransactionStatus::Success, $transaction->fresh()->status);
        $this->assertSame('500.0000', $this->walletBalance($user, Currency::INR));
        $this->assertNotNull($transaction->fresh()->last_checked_at);
    }

    public function test_status_check_cooldown(): void
    {
        config(['astropay.status_check.cooldown_seconds' => 30]);
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);
        Http::fake(['api.gpay.one/v1/payins/query' => Http::response($this->ok(['orderId' => $transaction->order_id, 'status' => 1]))]);

        $this->actingAs($user)->post(route('transactions.check', $transaction))->assertSessionHas('info');
        $this->actingAs($user)->post(route('transactions.check', $transaction))->assertSessionHas('warning');

        Http::assertSentCount(1);
    }

    public function test_failed_payout_found_by_status_check_is_refunded(): void
    {
        $user = User::factory()->create();
        $transaction = $this->heldPayout($user, 'pending');
        Http::fake(['api.gpay.one/v1/payouts/query' => Http::response($this->ok(['orderId' => $transaction->order_id, 'amount' => '400.0000', 'status' => 9, 'utr' => '']))]);

        $this->actingAs($this->admin())->post(route('admin.transactions.check', $transaction))->assertSessionHas('success');

        $this->assertSame(TransactionStatus::Failed, $transaction->fresh()->status);
        $this->assertSame('1000.0000', $this->walletBalance($user, Currency::PKR));
    }

    public function test_unknown_order_not_found_inside_grace_period_stays_unknown(): void
    {
        $user = User::factory()->create();
        $transaction = $this->heldPayout($user, 'unknown', now()->subMinutes(2));
        Http::fake(['api.gpay.one/v1/payouts/query' => Http::response($this->error(404, 'order not found'), 404)]);

        $this->actingAs($this->admin())->post(route('admin.transactions.check', $transaction))->assertSessionHas('error');

        $this->assertSame(TransactionStatus::Unknown, $transaction->fresh()->status);
        $this->assertSame('600.0000', $this->walletBalance($user, Currency::PKR));
    }

    public function test_unknown_order_not_found_after_grace_period_is_rejected_and_refunded(): void
    {
        $user = User::factory()->create();
        $transaction = $this->heldPayout($user, 'unknown', now()->subMinutes(20));
        Http::fake(['api.gpay.one/v1/payouts/query' => Http::response($this->error(404, 'order not found'), 404)]);

        $this->artisan('astropay:sync', ['order' => $transaction->order_id])->assertSuccessful();

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Rejected, $transaction->status);
        $this->assertSame('1000.0000', $this->walletBalance($user, Currency::PKR));
    }

    public function test_late_success_after_refund_is_flagged_and_can_be_reclaimed(): void
    {
        $user = User::factory()->create();
        $transaction = $this->heldPayout($user, 'unknown', now()->subMinutes(20));
        Http::fakeSequence('api.gpay.one/v1/payouts/query')
            ->push($this->error(404, 'order not found'), 404)
            ->push($this->ok(['orderId' => $transaction->order_id, 'amount' => '400.0000', 'status' => 10, 'utr' => '998877']));

        $this->artisan('astropay:sync', ['order' => $transaction->order_id])->assertSuccessful();
        $this->assertSame('1000.0000', $this->walletBalance($user, Currency::PKR));

        $this->artisan('astropay:sync', ['order' => $transaction->order_id])->assertSuccessful();
        $transaction->refresh();
        $this->assertSame(TransactionStatus::Rejected, $transaction->status);
        $this->assertTrue($transaction->needs_review);

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.transactions.resolve', $transaction), ['action' => TransactionProcessor::REVIEW_ACTION_RECLAIM, 'note' => 'Paid by AstroPay'])
            ->assertSessionHas('success');

        $transaction->refresh();
        $this->assertFalse($transaction->needs_review);
        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertSame('600.0000', $this->walletBalance($user, Currency::PKR));

        $this->actingAs($admin)->post(route('admin.transactions.resolve', $transaction), ['action' => TransactionProcessor::REVIEW_ACTION_RECLAIM, 'note' => 'again'])
            ->assertSessionHas('error');
    }

    public function test_review_credit_for_amount_mismatch_deposit(): void
    {
        $user = User::factory()->create();
        $transaction = $this->makeTransaction(['user_id' => $user->id]);
        Http::fake(['api.gpay.one/v1/payins/query' => Http::response($this->ok(['orderId' => $transaction->order_id, 'amount' => '450.0000', 'commission' => '30.0000', 'status' => 10]))]);

        $this->actingAs($this->admin())->post(route('admin.transactions.check', $transaction));
        $this->assertTrue($transaction->fresh()->needs_review);
        $this->assertSame('0.0000', $this->walletBalance($user, Currency::INR));

        $this->actingAs($this->admin())->post(route('admin.transactions.resolve', $transaction), ['action' => 'credit', 'note' => 'Customer paid 450'])
            ->assertSessionHas('success');

        $this->assertSame('450.0000', $this->walletBalance($user, Currency::INR));
        $this->assertFalse($transaction->fresh()->needs_review);
    }

    public function test_query_is_retried_on_server_errors(): void
    {
        $transaction = $this->makeTransaction();
        Http::fakeSequence('api.gpay.one/v1/payins/query')
            ->push($this->error(503, 'busy'), 503)
            ->push('not json', 502)
            ->push($this->ok(['orderId' => $transaction->order_id, 'amount' => '500.0000', 'commission' => '0', 'status' => 9]));

        $this->artisan('astropay:sync', ['order' => $transaction->order_id])->assertSuccessful();

        $this->assertSame(TransactionStatus::Failed, $transaction->fresh()->status);
        Http::assertSentCount(3);
    }

    public function test_bulk_sync_only_checks_old_unsettled_orders(): void
    {
        $old = $this->makeTransaction(['submitted_at' => now()->subMinutes(30)]);
        $fresh = $this->makeTransaction(['submitted_at' => now()]);
        $settled = $this->makeTransaction(['status' => TransactionStatus::Success, 'submitted_at' => now()->subHour()]);
        $awaiting = $this->makeTransaction(['type' => TransactionType::Payout, 'status' => TransactionStatus::AwaitingApproval, 'submitted_at' => null]);

        Http::fake(['api.gpay.one/v1/payins/query' => fn ($request) => Http::response($this->ok(['orderId' => $request['orderId'], 'status' => 1]))]);

        $this->artisan('astropay:sync', ['--older-than' => 10])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['orderId'] === $old->order_id);
        $this->assertNotNull($old->fresh()->last_checked_at);
        $this->assertNull($fresh->fresh()->last_checked_at);
        $this->assertNull($settled->fresh()->last_checked_at);
        $this->assertNull($awaiting->fresh()->last_checked_at);
    }
}
