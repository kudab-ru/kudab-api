<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE tg_outbox DROP CONSTRAINT IF EXISTS chk_tg_outbox_status");
        DB::statement("
            ALTER TABLE tg_outbox ADD CONSTRAINT chk_tg_outbox_status
            CHECK (status IN ('pending','sent','failed','skipped_dup','edited'))
        ");
    }

    public function down(): void
    {
        // верни прежний набор, если он отличался; базовый минимум:
//        DB::statement("ALTER TABLE tg_outbox DROP CONSTRAINT IF EXISTS chk_tg_outbox_status");
//        DB::statement("
//            ALTER TABLE tg_outbox ADD CONSTRAINT chk_tg_outbox_status
//            CHECK (status IN ('pending','sent'))
//        ");
    }
};
