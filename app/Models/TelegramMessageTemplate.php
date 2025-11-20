<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TelegramMessageTemplate extends Model
{
    protected $table = 'telegram.message_templates';

    protected $fillable = [
        'code',
        'locale',
        'name',
        'description',
        'body',
        'show_images',
        'max_images',
        'is_active',
    ];

    protected $casts = [
        'show_images' => 'bool',
        'is_active'   => 'bool',
    ];

    /**
     * Скоуп для активных шаблонов.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Удобный скоуп для поиска по коду + локали.
     */
    public function scopeForCodeAndLocale(Builder $query, string $code, string $locale = 'ru'): Builder
    {
        return $query
            ->where('code', $code)
            ->where('locale', $locale);
    }
}
