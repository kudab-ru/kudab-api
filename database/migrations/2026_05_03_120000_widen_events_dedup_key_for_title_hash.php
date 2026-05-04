<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // dedup_key v2.1: e2f-ветка теперь несёт title-hash суффикс ('|t=' + 10 hex).
        // Максимум: 'e2f:' (4) + UUID FIAS (36) + '|' + bucket5 (16) + '|t=' + 10 = 70.
        // Запас до 80 — на будущие расширения namespace.
        if (!Schema::hasColumn('events', 'dedup_key')) {
            return;
        }
        DB::statement('ALTER TABLE events ALTER COLUMN dedup_key TYPE VARCHAR(80)');
    }

    public function down(): void
    {
        // безопасный откат: 80 -> 66 возможен только если все значения короче 66.
        // На проде — оставляем 80; явно не сужаем.
    }
};
