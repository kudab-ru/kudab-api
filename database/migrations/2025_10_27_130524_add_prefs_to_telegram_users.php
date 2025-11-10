<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // jsonb-поле для настроек пользователя (флаги, фильтры и т.п.)
        DB::statement("
            ALTER TABLE telegram.users
            ADD COLUMN IF NOT EXISTS prefs jsonb NOT NULL DEFAULT '{}'::jsonb
        ");
        // опционально: индекс по ключам (если планируем искать по prefs -> '...'):
        // DB::statement("CREATE INDEX IF NOT EXISTS idx_tg_users_prefs_gin ON telegram.users USING GIN (prefs)");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE telegram.users DROP COLUMN IF EXISTS prefs");
        // DB::statement("DROP INDEX IF EXISTS idx_tg_users_prefs_gin");
    }
};
