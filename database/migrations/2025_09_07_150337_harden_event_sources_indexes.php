<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement("CREATE INDEX IF NOT EXISTS idx_event_sources_event_id ON event_sources (event_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_event_sources_context_post_id ON event_sources (context_post_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_event_sources_published_at ON event_sources (published_at)");

        // images: по умолчанию [] и NOT NULL
        DB::statement("UPDATE event_sources SET images = '[]' WHERE images IS NULL");
        DB::statement("ALTER TABLE event_sources ALTER COLUMN images SET DEFAULT '[]'::json");
        DB::statement("ALTER TABLE event_sources ALTER COLUMN images SET NOT NULL");

        // Уникальность пары (source, post_external_id) — только если post_external_id не NULL
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_event_sources_source_post
            ON event_sources (LOWER(source), post_external_id)
            WHERE post_external_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        // Откатываем индексы безопасно
        DB::statement("DROP INDEX IF EXISTS idx_event_sources_event_id");
        DB::statement("DROP INDEX IF EXISTS idx_event_sources_context_post_id");
        DB::statement("DROP INDEX IF EXISTS idx_event_sources_published_at");
        DB::statement("DROP INDEX IF EXISTS uniq_event_sources_source_post");
    }
};
