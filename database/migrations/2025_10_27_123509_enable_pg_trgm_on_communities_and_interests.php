<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Расширение триграмм для быстрого ILIKE
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Индексы для поиска по связанным полям
        DB::statement("CREATE INDEX IF NOT EXISTS idx_communities_name_trgm ON communities USING GIN ((lower(name)) gin_trgm_ops)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_communities_desc_trgm ON communities USING GIN ((lower(description)) gin_trgm_ops)");

        DB::statement("CREATE INDEX IF NOT EXISTS idx_interests_name_trgm  ON interests   USING GIN ((lower(name)) gin_trgm_ops)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_communities_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_communities_desc_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_interests_name_trgm');
    }
};
