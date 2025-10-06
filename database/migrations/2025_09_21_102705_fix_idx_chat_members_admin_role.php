<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public $withinTransaction = false;
    public function up(): void {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_chat_members_user_admin_active");
        DB::statement("
          CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_chat_members_user_admin_active
          ON telegram_chat_members (user_id, chat_id)
          WHERE left_at IS NULL AND role IN ('creator','admin')
        ");
    }
    public function down(): void {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_chat_members_user_admin_active");
        DB::statement("
          CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_chat_members_user_admin_active
          ON telegram_chat_members (user_id, chat_id)
          WHERE left_at IS NULL AND role IN ('creator','administrator')
        ");
    }
};
