<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GIN pg_trgm индекс на venues для name-first резолва площадки по имени.
 *
 * Зачем имя вообще резолвят по similarity — разобрано в kudab-parser,
 * App\Services\Venues\VenueNameMatcher (PR1 venue-name-resolution). Здесь
 * только индекс, который делает этот поиск быстрым.
 *
 * Expression-индекс на public.ru_normalize(name) (lower + ё→е) — переиспользует
 * существующую IMMUTABLE-функцию из 2026_02_19_add_ru_normalize_search и
 * совпадает с тем, как уже проиндексированы events.title / communities.name.
 * Отдельная колонка name_norm не нужна: выражение вычисляется из name
 * автоматически, индекс zero-maintenance при изменении имени.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS venues_name_ru_norm_trgm_idx '
            . 'ON venues USING gin (public.ru_normalize(name) gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS venues_name_ru_norm_trgm_idx');
    }
};
