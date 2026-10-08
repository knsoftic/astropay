<?php

namespace App\Models;

use App\Enums\AstroPay\Currency;
use App\Services\AstroPay\AstroPaySettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Merchant credentials and limits for one currency, edited in Admin → Settings.
 *
 * @property int $id
 * @property Currency $currency
 * @property string|null $merchant_key
 * @property string|null $secret_key
 * @property bool $enabled
 * @property string|null $deposit_min
 * @property string|null $deposit_max
 * @property string|null $payout_min
 * @property string|null $payout_max
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AstroPayAccount extends Model
{
    protected $table = 'astropay_accounts';

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['merchant_key', 'secret_key'];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'merchant_key' => 'encrypted',
            'secret_key' => 'encrypted',
            'enabled' => 'boolean',
            'deposit_min' => 'decimal:2',
            'deposit_max' => 'decimal:2',
            'payout_min' => 'decimal:2',
            'payout_max' => 'decimal:2',
            'updated_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $forget = fn () => app(AstroPaySettings::class)->forget();

        static::saved($forget);
        static::deleted($forget);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
