<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // считаем от geometry(Point,4326), а не от latitude/longitude
        DB::statement("
            ALTER TABLE events
            ADD COLUMN IF NOT EXISTS lat_round numeric(9,3)
                GENERATED ALWAYS AS (
                    CASE WHEN location IS NULL THEN NULL
                         ELSE round(st_y(location)::numeric, 3)
                    END
                ) STORED,
            ADD COLUMN IF NOT EXISTS lon_round numeric(9,3)
                GENERATED ALWAYS AS (
                    CASE WHEN location IS NULL THEN NULL
                         ELSE round(st_x(location)::numeric, 3)
                    END
                ) STORED
        ");

        DB::statement('CREATE INDEX IF NOT EXISTS idx_events_start_time ON events (start_time)');

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_events_time_geo
            ON events (start_time, lat_round, lon_round)
            WHERE lat_round IS NOT NULL AND lon_round IS NOT NULL AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_events_time_geo');
        DB::statement('DROP INDEX IF EXISTS idx_events_start_time');
        DB::statement('ALTER TABLE events DROP COLUMN IF EXISTS lat_round');
        DB::statement('ALTER TABLE events DROP COLUMN IF EXISTS lon_round');
    }
};
