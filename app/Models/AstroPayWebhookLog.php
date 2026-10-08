<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $transaction_id
 * @property string $type
 * @property string $currency
 * @property string|null $order_id
 * @property string|null $ip
 * @property string|null $content_type
 * @property array<string, mixed>|null $payload
 * @property bool $signature_valid
 * @property string $outcome
 * @property int $http_status
 * @property string|null $message
 */
class AstroPayWebhookLog extends Model
{
    protected $table = 'astropay_webhook_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'http_status' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(AstroPayTransaction::class, 'transaction_id');
    }
}
