<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Конфиг внешнего structured-источника (per source+city), редактируемый
 * суперадмином из админки. Раньше — статичный parser config/yandex_afisha.php.
 *
 * Пишет ТОЛЬКО api (admin-эндпоинты PR4); parser читает свежим (PR3) с fallback
 * на config(). Секреты (UA/headless/таймауты) тут НЕ хранятся — только в env.
 *
 * sections: [{slug, content_kind?, audience?, enabled}] — разделы listing'а.
 */
class SourceConfig extends Model
{
    protected $fillable = [
        'source_slug',
        'city_slug',
        'enabled',
        'json_ld_bypass_enabled',
        'listing_limit_per_run',
        'listing_limit_per_section',
        'sections',
        'run_requested_at',
    ];

    protected $casts = [
        'enabled'                   => 'boolean',
        'json_ld_bypass_enabled'    => 'boolean',
        'listing_limit_per_run'     => 'integer',
        'listing_limit_per_section' => 'integer',
        'sections'                  => 'array',
        'run_requested_at'          => 'datetime',
        'created_at'                => 'datetime',
        'updated_at'                => 'datetime',
    ];
}
