<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Расширение триграмм для быстрого LIKE/ILIKE
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // GIN-индексы по title/description для ILIKE '%q%'
        DB::statement("CREATE INDEX IF NOT EXISTS idx_events_title_trgm ON events USING GIN ((lower(title)) gin_trgm_ops)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_events_desc_trgm  ON events USING GIN ((lower(description)) gin_trgm_ops)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_events_title_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_events_desc_trgm');
    }
};
