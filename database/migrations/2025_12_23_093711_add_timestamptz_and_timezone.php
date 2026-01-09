<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // считаем, что текущие значения в start_time/end_time УЖЕ UTC “как число”
        DB::statement("
            ALTER TABLE events
              ALTER COLUMN start_time TYPE timestamptz USING start_time AT TIME ZONE 'UTC',
              ALTER COLUMN end_time   TYPE timestamptz USING end_time   AT TIME ZONE 'UTC'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE events
              ALTER COLUMN start_time TYPE timestamp(0) without time zone USING (start_time AT TIME ZONE 'UTC'),
              ALTER COLUMN end_time   TYPE timestamp(0) without time zone USING (end_time   AT TIME ZONE 'UTC')
        ");
    }
};
