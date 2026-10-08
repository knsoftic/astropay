<?php

namespace App\Models;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\PaymentMethod;
use App\Enums\AstroPay\TransactionStatus;
use App\Enums\AstroPay\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property TransactionType $type
 * @property Currency $currency
 * @property PaymentMethod|null $payment_method
 * @property string $order_id
 * @property string|null $idempotency_key
 * @property int $attempts
 * @property string $amount
 * @property string|null $commission
 * @property string|null $net_amount
 * @property string|null $settled_amount
 * @property TransactionStatus $status
 * @property int|null $gateway_status
 * @property bool $is_test
 * @property string|null $pay_url
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property string|null $beneficiary_account
 * @property string|null $beneficiary_bank_code
 * @property string|null $beneficiary_phone
 * @property string|null $beneficiary_name
 * @property string|null $utr
 * @property string|null $supplemented_utr
 * @property string|null $remark
 * @property int|null $error_code
 * @property string|null $error_message
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $rejected_by
 * @property \Illuminate\Support\Carbon|null $rejected_at
 * @property string|null $gateway_create_time
 * @property string|null $gateway_update_time
 * @property array<string, mixed>|null $last_callback
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $callback_received_at
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class AstroPayTransaction extends Model
{
    use HasUuids;

    protected $table = 'astropay_transactions';

    /**
     * Every write goes through the services, never mass assignment from requests.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['beneficiary_account', 'beneficiary_phone', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'approved_by' => 'integer',
            'rejected_by' => 'integer',
            'reviewed_by' => 'integer',
            'type' => TransactionType::class,
            'currency' => Currency::class,
            'payment_method' => PaymentMethod::class,
            'status' => TransactionStatus::class,
            'amount' => 'decimal:4',
            'commission' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'settled_amount' => 'decimal:4',
            'gateway_status' => 'integer',
            'attempts' => 'integer',
            'error_code' => 'integer',
            'is_test' => 'boolean',
            'needs_review' => 'boolean',
            'beneficiary_account' => 'encrypted',
            'beneficiary_phone' => 'encrypted',
            'last_callback' => 'array',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'submitted_at' => 'datetime',
            'callback_received_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Only the public uuid column is auto-generated; the primary key stays an integer.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function walletEntries(): HasMany
    {
        return $this->hasMany(WalletEntry::class, 'transaction_id');
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(AstroPayWebhookLog::class, 'transaction_id');
    }

    public function isDeposit(): bool
    {
        return $this->type === TransactionType::Deposit;
    }

    public function isPayout(): bool
    {
        return $this->type === TransactionType::Payout;
    }

    /**
     * The hosted checkout link is short-lived; only offer it while the
     * deposit is pending and recent.
     */
    public function canContinuePayment(): bool
    {
        return $this->isDeposit()
            && $this->status === TransactionStatus::Pending
            && filled($this->pay_url)
            && $this->submitted_at !== null
            && $this->submitted_at->gt(now()->subMinutes((int) config('astropay.deposits.pay_url_ttl_minutes', 30)));
    }

    /**
     * Status checks only make sense once the order has been sent to AstroPay.
     */
    public function canCheckStatus(): bool
    {
        return $this->submitted_at !== null
            && $this->status !== TransactionStatus::AwaitingApproval;
    }

    public function canSubmitUtr(): bool
    {
        return $this->isDeposit()
            && $this->currency->supportsUtrTools()
            && in_array($this->status, [TransactionStatus::Pending, TransactionStatus::Unknown, TransactionStatus::Failed], true);
    }

    /**
     * Error text that is safe to show to the customer. AstroPay 400 messages
     * are validation messages; anything else (or Chinese internal messages)
     * is replaced with a generic sentence.
     */
    public function userErrorMessage(): ?string
    {
        // Errors on a withdrawal back in the approval queue are for the admin only.
        if ($this->error_message === null || $this->error_message === '' || $this->status === TransactionStatus::AwaitingApproval) {
            return null;
        }

        // Admin rejection reason, or an AstroPay validation message.
        if ($this->rejected_by !== null
            || ($this->error_code === 400 && preg_match('/\p{Han}/u', $this->error_message) !== 1)) {
            return $this->error_message;
        }

        return match ($this->status) {
            TransactionStatus::Unknown => 'We are confirming this order with the payment provider. Check its status again in a few minutes.',
            TransactionStatus::Rejected => 'The payment provider could not process this request.',
            default => 'The payment provider reported a problem with this order.',
        };
    }

    /**
     * Beneficiary account with all but the last four characters masked.
     */
    public function maskedBeneficiaryAccount(): ?string
    {
        $account = $this->beneficiary_account;

        if ($account === null || $account === '') {
            return null;
        }

        if (str_contains($account, '@')) {
            [$local, $domain] = explode('@', $account, 2);

            return mb_substr($local, 0, 2).str_repeat('*', max(mb_strlen($local) - 2, 1)).'@'.$domain;
        }

        $visible = mb_substr($account, -4);

        return str_repeat('*', max(mb_strlen($account) - 4, 0)).$visible;
    }

    /**
     * @param  Builder<AstroPayTransaction>  $query
     * @return Builder<AstroPayTransaction>
     */
    public function scopeAwaitingGateway(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TransactionStatus::Initiated->value,
            TransactionStatus::Pending->value,
            TransactionStatus::Unknown->value,
        ])->whereNotNull('submitted_at');
    }
}
