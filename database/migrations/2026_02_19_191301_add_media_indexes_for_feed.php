<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS event_sources_event_id_idx ON event_sources (event_id)");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS attachments_parent_type_parent_id_type_idx ON attachments (parent_type, parent_id, type)");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS event_sources_event_id_idx");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS attachments_parent_type_parent_id_type_idx");
    }
};
