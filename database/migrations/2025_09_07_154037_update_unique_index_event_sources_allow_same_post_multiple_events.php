<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Старая уникальность: (LOWER(source), post_external_id)
        DB::statement("DROP INDEX IF EXISTS uniq_event_sources_source_post");

        // Новая уникальность: (LOWER(source), post_external_id, event_id)
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_event_sources_source_post_event
            ON event_sources (LOWER(source), post_external_id, event_id)
            WHERE post_external_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS uniq_event_sources_source_post_event");
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_event_sources_source_post
            ON event_sources (LOWER(source), post_external_id)
            WHERE post_external_id IS NOT NULL
        ");
    }
};
