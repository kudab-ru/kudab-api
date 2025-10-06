<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    // нужно для CREATE INDEX CONCURRENTLY
    public $withinTransaction = false;

    public function up(): void
    {
        // --- CHECK template_mode
        DB::statement("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = 'chk_tg_rules_template_mode'
            ) THEN
                ALTER TABLE tg_broadcast_rules
                ADD CONSTRAINT chk_tg_rules_template_mode
                CHECK (template_mode IN ('static','rotate','random'));
            END IF;
        END$$;");

        // --- CHECK schedule_mode
        DB::statement("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = 'chk_tg_rules_schedule_mode'
            ) THEN
                ALTER TABLE tg_broadcast_rules
                ADD CONSTRAINT chk_tg_rules_schedule_mode
                CHECK (schedule_mode IN ('interval','window','rrule'));
            END IF;
        END$$;");

        // --- индексы: правила/чаты (обычная оптимизация)
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rules_enabled_chat ON tg_broadcast_rules (enabled, chat_id)");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rules_template      ON tg_broadcast_rules (template_id)");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_chats_type          ON telegram_chats (type)");

        // --- outbox: быстрый выбор pending
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_outbox_pending ON tg_outbox (scheduled_at, id) WHERE status='pending'");

        // --- ВАЖНО: перед UNIQUE — чистим дубликаты payload_hash (оставляем минимальный id в группе)
        DB::statement("
        WITH dups AS (
          SELECT payload_hash, MIN(id) AS keep_id
          FROM tg_outbox
          WHERE payload_hash IS NOT NULL
          GROUP BY payload_hash
          HAVING COUNT(*) > 1
        ),
        to_del AS (
          SELECT o.id
          FROM tg_outbox o
          JOIN dups d ON o.payload_hash = d.payload_hash
          WHERE o.id <> d.keep_id
        )
        DELETE FROM tg_outbox
        WHERE id IN (SELECT id FROM to_del);
        ");

        // --- теперь можно вешать уникальный индекс (идемпотентность обычных постановок)
        DB::statement("CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS ux_outbox_payload_hash ON tg_outbox (payload_hash)");

        // --- deliveries: дедуп в разрезе чата/ивента и по dedup_key
        DB::statement("CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS ux_deliv_chat_event ON tg_event_deliveries (chat_id, event_id)");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_deliv_chat_dedup       ON tg_event_deliveries (chat_id, dedup_key) WHERE dedup_key IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tg_broadcast_rules DROP CONSTRAINT IF EXISTS chk_tg_rules_schedule_mode");
        // при желании можно снять и template_mode:
        // DB::statement("ALTER TABLE tg_broadcast_rules DROP CONSTRAINT IF EXISTS chk_tg_rules_template_mode");

        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_rules_enabled_chat");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_rules_template");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_chats_type");

        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_outbox_pending");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS ux_outbox_payload_hash");

        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS ux_deliv_chat_event");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_deliv_chat_dedup");
    }
};
