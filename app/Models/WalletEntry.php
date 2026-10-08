<?php

namespace App\Models;

use App\Enums\WalletEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $wallet_id
 * @property int|null $transaction_id
 * @property WalletEntryType $type
 * @property string $amount
 * @property string $balance_after
 * @property string $description
 * @property int|null $created_by
 */
class WalletEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => WalletEntryType::class,
            'amount' => 'decimal:4',
            'balance_after' => 'decimal:4',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(AstroPayTransaction::class, 'transaction_id');
    }
}
