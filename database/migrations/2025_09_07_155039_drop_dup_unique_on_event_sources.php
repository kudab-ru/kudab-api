<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE event_sources DROP CONSTRAINT IF EXISTS event_sources_event_id_source_post_external_id_unique");
    }
    public function down(): void
    {
        DB::statement("
            ALTER TABLE event_sources
            ADD CONSTRAINT event_sources_event_id_source_post_external_id_unique
            UNIQUE (event_id, source, post_external_id)
        ");
    }
};
