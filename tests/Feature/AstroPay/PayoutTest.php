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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithAstroPay;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use InteractsWithAstroPay, RefreshDatabase;

    private const TRC20 = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAstroPay();
    }

    private function withdraw(User $user, array $input): TestResponse
    {
        return $this->actingAs($user)->from(route('withdrawals.create'))->post(route('withdrawals.store'), $input + [
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function payoutCreated(): array
    {
        return $this->ok(['orderId' => 'ignored', 'amount' => '200.00', 'commission' => 14, 'netAmount' => 214, 'utr' => '', 'status' => 1]);
    }

    public function test_withdrawal_request_holds_funds_and_waits_for_approval(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::PKR, '5000');

        $response = $this->withdraw($user, [
            'currency' => 'PKR', 'payment_method' => 'JAZZCASH', 'amount' => '1200.50',
            'account' => '+92 300 1234567', 'person_name' => 'Ali Khan',
        ]);

        $transaction = AstroPayTransaction::query()->sole();
        $response->assertRedirect(route('transactions.show', $transaction))->assertSessionHas('success');

        $this->assertSame(TransactionStatus::AwaitingApproval, $transaction->status);
        $this->assertSame('03001234567', $transaction->beneficiary_account);
        $this->assertSame('03001234567', $transaction->beneficiary_phone);
        $this->assertSame('3799.5000', $this->walletBalance($user, Currency::PKR));
        Http::assertNothingSent();

        // Beneficiary account is encrypted at rest.
        $raw = \DB::table('astropay_transactions')->value('beneficiary_account');
        $this->assertNotSame('03001234567', $raw);
    }

    public function test_insufficient_wallet_balance_is_refused(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::INR, '100');

        $this->withdraw($user, [
            'currency' => 'INR', 'payment_method' => 'UPI', 'amount' => '100.01',
            'account' => 'asha@okhdfcbank', 'account_phone' => '9876543210', 'person_name' => 'Asha Rao',
        ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('astropay_transactions', 0);
        $this->assertSame('100.0000', $this->walletBalance($user, Currency::INR));
    }

    public function test_beneficiary_fields_are_validated_per_method(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::INR, '1000');
        $this->fundWallet($user, Currency::USDT, '1000');

        $base = ['currency' => 'INR', 'payment_method' => 'UPI', 'amount' => '100', 'account_phone' => '9876543210', 'person_name' => 'Asha Rao'];

        $this->withdraw($user, $base + ['account' => '50100012345678'])->assertSessionHasErrors('bank_code');
        $this->withdraw($user, $base + ['account' => '50100012345678', 'bank_code' => 'HDFC123'])->assertSessionHasErrors('bank_code');
        $this->withdraw($user, $base + ['account' => 'not a upi'])->assertSessionHasErrors('account');
        $this->withdraw($user, ['account_phone' => '12345'] + $base + ['account' => 'asha@upi'])->assertSessionHasErrors('account_phone');
        $this->withdraw($user, ['person_name' => ''] + $base + ['account' => 'asha@upi'])->assertSessionHasErrors('person_name');
        $this->withdraw($user, ['payment_method' => ''] + $base + ['account' => 'asha@upi'])->assertSessionHasErrors('payment_method');
        $this->withdraw($user, ['currency' => 'USDT', 'payment_method' => '', 'amount' => '10', 'account' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6u'])->assertSessionHasErrors('account');

        $this->assertDatabaseCount('astropay_transactions', 0);
    }

    public function test_approval_sends_the_jazzcash_payload(): void
    {
        Http::fake(['api.gpay.one/v1/payouts/create' => Http::response($this->payoutCreated())]);
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::PKR, '5000');
        $this->withdraw($user, ['currency' => 'PKR', 'payment_method' => 'JAZZCASH', 'amount' => '200', 'account' => '03001234567', 'person_name' => 'Ali Khan']);
        $transaction = AstroPayTransaction::query()->sole();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.transactions.approve', $transaction))->assertSessionHas('success');

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertSame('14.0000', $transaction->commission);
        $this->assertSame('214.0000', $transaction->net_amount);
        $this->assertSame($admin->id, $transaction->approved_by);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.gpay.one/v1/payouts/create'
            && $r['merchantKey'] === 'mk_pkr'
            && $r['orderId'] === $transaction->order_id
            && $r['amount'] === '200.00'
            && $r['callbackUrl'] === 'https://merchant.test/webhooks/astropay/payout/PKR'
            && $r['accountType'] === 'JAZZCASH'
            && $r['account'] === '03001234567'
            && $r['bank_code'] === ''
            && $r['accountPhone'] === '03001234567'
            && $r['personName'] === 'Ali Khan');
    }

    public function test_inr_bank_account_and_upi_payloads(): void
    {
        Http::fake(['api.gpay.one/v1/payouts/create' => Http::response($this->payoutCreated())]);
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::INR, '5000');
        $admin = $this->admin();

        $this->withdraw($user, ['currency' => 'INR', 'payment_method' => 'UPI', 'amount' => '200', 'account' => '5010 0012 345678', 'bank_code' => 'hdfc0000123', 'account_phone' => '9876543210', 'person_name' => 'Asha Rao']);
        $this->withdraw($user, ['currency' => 'INR', 'payment_method' => 'UPI', 'amount' => '300', 'account' => 'asha@okhdfcbank', 'bank_code' => 'IGNORED', 'account_phone' => '+919876543210', 'person_name' => 'Asha Rao']);

        foreach (AstroPayTransaction::query()->get() as $transaction) {
            $this->actingAs($admin)->post(route('admin.transactions.approve', $transaction));
        }

        Http::assertSent(fn (Request $r) => $r['amount'] === '200.00' && $r['accountType'] === 'UPI' && $r['account'] === '50100012345678' && $r['bank_code'] === 'HDFC0000123' && $r['accountPhone'] === '9876543210');
        Http::assertSent(fn (Request $r) => $r['amount'] === '300.00' && $r['account'] === 'asha@okhdfcbank' && $r['bank_code'] === '' && $r['accountPhone'] === '9876543210');
    }

    public function test_usdt_payload_omits_account_type_and_unused_fields(): void
    {
        Http::fake(['api.gpay.one/v1/payouts/create' => Http::response($this->payoutCreated())]);
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::USDT, '500');

        $this->withdraw($user, ['currency' => 'USDT', 'amount' => '200', 'account' => self::TRC20, 'person_name' => '']);
        $transaction = AstroPayTransaction::query()->sole();
        $this->actingAs($this->admin())->post(route('admin.transactions.approve', $transaction));

        Http::assertSent(function (Request $r) {
            $data = $r->data();

            return $r['merchantKey'] === 'mk_usdt'
                && $data['account'] === self::TRC20
                && ! array_key_exists('accountType', $data)
                && ! array_key_exists('bank_code', $data)
                && ! array_key_exists('accountPhone', $data)
                && ! array_key_exists('personName', $data);
        });
    }

    public function test_refused_payout_returns_to_the_approval_queue_and_retries_with_a_new_order_id(): void
    {
        Http::fakeSequence('api.gpay.one/v1/payouts/create')
            ->push($this->error(400, 'insufficient balance'), 400)
            ->push($this->payoutCreated());

        $user = User::factory()->create();
        $this->fundWallet($user, Currency::BDT, '1000');
        $this->withdraw($user, ['currency' => 'BDT', 'payment_method' => 'NAGAD', 'amount' => '200', 'account' => '01712345678', 'person_name' => 'Rahim Uddin']);
        $transaction = AstroPayTransaction::query()->sole();
        $firstOrderId = $transaction->order_id;
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.transactions.approve', $transaction))->assertSessionHas('error');

        $transaction->refresh();
        $this->assertSame(TransactionStatus::AwaitingApproval, $transaction->status);
        $this->assertSame('insufficient balance', $transaction->error_message);
        $this->assertNull($transaction->userErrorMessage(), 'merchant-side errors are not shown to the user');
        $this->assertSame('800.0000', $this->walletBalance($user, Currency::BDT), 'still held');

        $this->actingAs($admin)->post(route('admin.transactions.approve', $transaction))->assertSessionHas('success');

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertNotSame($firstOrderId, $transaction->order_id);
        $this->assertSame(2, $transaction->attempts);
    }

    public function test_timeout_on_payout_leaves_it_unknown_and_blocks_reapproval(): void
    {
        Http::fake(['api.gpay.one/v1/payouts/create' => Http::failedConnection('timed out')]);
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::PKR, '1000');
        $this->withdraw($user, ['currency' => 'PKR', 'payment_method' => 'EASYPAISA', 'amount' => '500', 'account' => '03451234567', 'person_name' => 'Sara Ahmed']);
        $transaction = AstroPayTransaction::query()->sole();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.transactions.approve', $transaction))->assertSessionHas('warning');
        $this->actingAs($admin)->post(route('admin.transactions.approve', $transaction))->assertSessionHas('error');

        $this->assertSame(TransactionStatus::Unknown, $transaction->fresh()->status);
        $this->assertSame('500.0000', $this->walletBalance($user, Currency::PKR));
        Http::assertSentCount(1);
    }

    public function test_admin_rejection_refunds_the_user(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::INR, '1000');
        $this->withdraw($user, ['currency' => 'INR', 'payment_method' => 'UPI', 'amount' => '400', 'account' => 'asha@upi', 'account_phone' => '9876543210', 'person_name' => 'Asha Rao']);
        $transaction = AstroPayTransaction::query()->sole();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.transactions.reject', $transaction), ['reason' => 'Name does not match KYC'])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.transactions.reject', $transaction), ['reason' => 'again'])->assertSessionHas('error');

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Rejected, $transaction->status);
        $this->assertSame($admin->id, $transaction->rejected_by);
        $this->assertSame('Rejected by admin: Name does not match KYC', $transaction->userErrorMessage());
        $this->assertSame('1000.0000', $this->walletBalance($user, Currency::INR));
        Http::assertNothingSent();
    }

    public function test_without_approval_the_payout_is_sent_immediately_and_refused_ones_refunded(): void
    {
        config(['astropay.payouts.require_approval' => false]);
        Http::fakeSequence('api.gpay.one/v1/payouts/create')
            ->push($this->payoutCreated())
            ->push($this->error(400, 'invalid account'), 400);

        $user = User::factory()->create();
        $this->fundWallet($user, Currency::BDT, '1000');

        $this->withdraw($user, ['currency' => 'BDT', 'payment_method' => 'BKASH', 'amount' => '200', 'account' => '01712345678', 'person_name' => 'Rahim Uddin']);
        $this->withdraw($user, ['currency' => 'BDT', 'payment_method' => 'BKASH', 'amount' => '300', 'account' => '01812345678', 'person_name' => 'Rahim Uddin'])
            ->assertSessionHas('error');

        [$sent, $refused] = AstroPayTransaction::query()->orderBy('id')->get()->all();
        $this->assertSame(TransactionStatus::Pending, $sent->status);
        $this->assertSame(TransactionStatus::Rejected, $refused->status);
        $this->assertSame('800.0000', $this->walletBalance($user, Currency::BDT));
    }

    public function test_non_admins_cannot_approve(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::PKR, '1000');
        $this->withdraw($user, ['currency' => 'PKR', 'payment_method' => 'JAZZCASH', 'amount' => '100', 'account' => '03001234567', 'person_name' => 'Ali Khan']);
        $transaction = AstroPayTransaction::query()->sole();

        $this->actingAs($user)->post(route('admin.transactions.approve', $transaction))->assertForbidden();
        $this->assertSame(TransactionStatus::AwaitingApproval, $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_double_submitted_withdrawal_holds_funds_once(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->fundWallet($user, Currency::PKR, '1000');
        $input = ['currency' => 'PKR', 'payment_method' => 'JAZZCASH', 'amount' => '100', 'account' => '03001234567', 'person_name' => 'Ali Khan', 'idempotency_key' => (string) Str::uuid()];

        $this->actingAs($user)->post(route('withdrawals.store'), $input);
        $this->actingAs($user)->post(route('withdrawals.store'), $input);

        $this->assertDatabaseCount('astropay_transactions', 1);
        $this->assertSame('900.0000', $this->walletBalance($user, Currency::PKR));
    }
}
