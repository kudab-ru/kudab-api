<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс под «когда источник писал в последний раз».
 *
 * У context_posts есть индекс по published_at и FK на communities, но нет
 * индекса ПО community_id. Из-за этого max(published_at) для одного
 * сообщества (WebVenueDetailResource::socialAccounts(), withMax в
 * VenuesController::loadSources) Postgres считает как Index Scan Backward по
 * context_posts_published_at_index с фильтром community_id: он идёт от
 * самого свежего поста ВСЕЙ таблицы назад, пока не встретит пост нужного
 * сообщества. Для площадки, чей источник давно молчит, это проход по всей
 * таблице: у LOFT36 (сообщество 22, последний пост 2020-03-31) —
 * «Rows Removed by Filter: 13210, Buffers: shared hit=12428», ~20 мс на
 * запрос при ~90 мс всей страницы.
 *
 * Цена растёт с общим объёмом постов, а не с числом постов сообщества:
 * context_posts прибавляет 2–3.5 тыс. строк в месяц, а context:cleanup гасит
 * старьё мягко (deleted_at), то есть из индекса строки не уходят — 7.5 тыс.
 * из 13 тыс. сегодня уже погашены и всё равно просматриваются.
 *
 * Составной btree (community_id, published_at) превращает это в один спуск
 * по индексу: 12428 буферов → 3, ~20 мс → 0.1 мс. Для каталога (loadSources
 * рассчитан на список площадок) те же 51 сообщество: 109 240 буферов /
 * ~120 мс → 153 буфера / ~1 мс.
 *
 * Порядок колонок обязателен именно такой: равенство по community_id, потом
 * упорядоченный published_at — тогда максимум берётся с конца диапазона без
 * сортировки. Обратный порядок даёт ровно сегодняшний план.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('context_posts')) {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS context_posts_community_published_index '
            .'ON context_posts (community_id, published_at)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('context_posts')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS context_posts_community_published_index');
    }
};
