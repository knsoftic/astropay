<?php

namespace App\Models;

use App\Services\AstroPay\AstroPaySettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Key/value AstroPay connection settings edited in Admin → Settings.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 */
class AstroPaySetting extends Model
{
    protected $table = 'astropay_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
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
