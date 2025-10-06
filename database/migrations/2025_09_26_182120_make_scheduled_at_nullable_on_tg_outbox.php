<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // убрать NOT NULL и дефолт (если был), чтобы код мог ставить NULL
        DB::statement("ALTER TABLE tg_outbox ALTER COLUMN scheduled_at DROP NOT NULL");
        DB::statement("ALTER TABLE tg_outbox ALTER COLUMN scheduled_at DROP DEFAULT");
    }

    public function down(): void
    {
        // откат небезопасен, если есть NULL; оставим как есть
        // при необходимости можно принудительно заполнить значениями и вернуть NOT NULL
    }
};
