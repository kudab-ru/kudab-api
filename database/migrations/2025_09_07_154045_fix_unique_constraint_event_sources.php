<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Сносим оба возможных варианта старой уникальности
        DB::statement("ALTER TABLE event_sources DROP CONSTRAINT IF EXISTS event_sources_source_post_external_id_unique");
        DB::statement("DROP INDEX IF EXISTS uniq_event_sources_source_post");
        // Если ранее создавали уникальный ИНДЕКС c event_id — уберём, чтобы не мешал констрэйнту
        DB::statement("DROP INDEX IF EXISTS uniq_event_sources_source_post_event");

        // Ставим корректную УНИКАЛЬНУЮ КОНСТРАИНТУ на колонки
        // (частичных констраинт в PG нет — это ок; NULL-ы по стандарту не конфликтуют)
        DB::statement("
            ALTER TABLE event_sources
            ADD CONSTRAINT uq_event_sources_source_post_event
            UNIQUE (source, post_external_id, event_id)
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE event_sources DROP CONSTRAINT IF EXISTS uq_event_sources_source_post_event");
        // Можем вернуть старую (не нужно, но для симметрии):
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_event_sources_source_post
            ON event_sources (LOWER(source), post_external_id)
            WHERE post_external_id IS NOT NULL
        ");
    }
};
