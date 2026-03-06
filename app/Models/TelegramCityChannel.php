<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramCityChannel extends Model
{
    protected $table = 'telegram.city_channels';

    protected $fillable = [
        'city_id',
        'telegram_url',
        'telegram_username',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->telegram_url = trim((string) $model->telegram_url);

            if ($model->telegram_username !== null) {
                $username = trim((string) $model->telegram_username);
                $username = ltrim($username, '@');
                $model->telegram_username = $username !== '' ? mb_strtolower($username) : null;
            }
        });
    }
}
