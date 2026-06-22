<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Журнал прогонов сбора внешнего источника — данные для статус-блока админки.
 * Пишет parser (PR3) на batch-done; читает api (статус-эндпоинт PR4).
 */
class SourceRun extends Model
{
    protected $fillable = [
        'source_slug',
        'city_slug',
        'started_at',
        'finished_at',
        'status',
        'urls_total',
        'posts_ok',
        'posts_failed',
        'error_text',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'urls_total'   => 'integer',
        'posts_ok'     => 'integer',
        'posts_failed' => 'integer',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];
}
