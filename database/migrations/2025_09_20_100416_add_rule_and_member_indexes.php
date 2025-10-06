<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CONCURRENTLY нельзя в транзакции
    public $withinTransaction = false;

    public function up(): void
    {
        // 1 активное правило на чат
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uq_rules_chat_single_enabled
            ON tg_broadcast_rules (chat_id)
            WHERE enabled = true
        ");

        // Быстрый выбор «мои чаты» (активный админ/создатель)
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_chat_members_user_admin_active
            ON telegram_chat_members (user_id, chat_id)
            WHERE left_at IS NULL AND role IN ('creator','administrator')
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS uq_rules_chat_single_enabled");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_chat_members_user_admin_active");
    }
};
