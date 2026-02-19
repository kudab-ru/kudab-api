<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // 1) функция нормализации
        DB::statement(<<<SQL
CREATE OR REPLACE FUNCTION public.ru_normalize(t text)
RETURNS text
LANGUAGE sql
IMMUTABLE
AS $$
  SELECT lower(translate(coalesce(t, ''), 'Ёё', 'Ее'));
$$;
SQL);

        // 2) trigram (если права позволяют)
        DB::statement("CREATE EXTENSION IF NOT EXISTS pg_trgm;");

        // 3) индексы под LIKE '%...%' по нормализованному тексту
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS events_title_norm_trgm_idx ON events USING gin (public.ru_normalize(title) gin_trgm_ops);");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS events_desc_norm_trgm_idx  ON events USING gin (public.ru_normalize(description) gin_trgm_ops);");

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS communities_name_norm_trgm_idx ON communities USING gin (public.ru_normalize(name) gin_trgm_ops);");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS communities_desc_norm_trgm_idx ON communities USING gin (public.ru_normalize(description) gin_trgm_ops);");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS events_title_norm_trgm_idx;");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS events_desc_norm_trgm_idx;");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS communities_name_norm_trgm_idx;");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS communities_desc_norm_trgm_idx;");

        DB::statement("DROP FUNCTION IF EXISTS public.ru_normalize(text);");
    }
};
