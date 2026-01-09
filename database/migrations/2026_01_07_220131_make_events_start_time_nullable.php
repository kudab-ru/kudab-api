<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // v6: разрешаем date-only события (start_time = NULL)
        DB::statement('ALTER TABLE events ALTER COLUMN start_time DROP NOT NULL');
    }

    public function down(): void
    {
        // rollback: чтобы вернуть NOT NULL — заполняем NULL-значения безопасным дефолтом
        // Берём start_date (если есть), иначе CURRENT_DATE. Время ставим 00:00 UTC.
        DB::statement("
            UPDATE events
            SET start_time = (COALESCE(start_date, CURRENT_DATE)::timestamp AT TIME ZONE 'UTC')
            WHERE start_time IS NULL
        ");

        DB::statement('ALTER TABLE events ALTER COLUMN start_time SET NOT NULL');
    }
};
