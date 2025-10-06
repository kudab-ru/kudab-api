<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Нужен для CREATE UNIQUE INDEX CONCURRENTLY
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uq_outbox_pending_chat_event
            ON tg_outbox (chat_id, event_id)
            WHERE status = 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS uq_outbox_pending_chat_event");
    }
};
