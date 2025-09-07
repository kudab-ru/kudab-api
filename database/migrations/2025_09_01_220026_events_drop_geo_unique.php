<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_events_time_geo;');
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_events_time_geo
            ON events (start_time, lat_round, lon_round)
            WHERE lat_round IS NOT NULL AND lon_round IS NOT NULL AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_events_time_geo;');
        DB::statement("
            CREATE UNIQUE INDEX uniq_events_time_geo
            ON events (start_time, lat_round, lon_round)
            WHERE lat_round IS NOT NULL AND lon_round IS NOT NULL AND deleted_at IS NULL
        ");
    }
};
