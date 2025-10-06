<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            if (!Schema::hasColumn('telegram_chats', 'link_status')) {
                $table->string('link_status', 16)->default('unlinked')->after('username');
            }
            if (!Schema::hasColumn('telegram_chats', 'linked_at')) {
                $table->timestampTz('linked_at')->nullable()->after('link_status');
            }
            if (!Schema::hasColumn('telegram_chats', 'unlinked_at')) {
                $table->timestampTz('unlinked_at')->nullable()->after('linked_at');
            }
        });

        // CHECK на допустимые значения статуса
        DB::statement(<<<'SQL'
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_telegram_chats_link_status') THEN
    ALTER TABLE telegram_chats
      ADD CONSTRAINT chk_telegram_chats_link_status
      CHECK (link_status IN ('linked','unlinked','kicked','unknown'));
  END IF;
END$$;
SQL);

        // Индекс по статусу
        DB::statement("CREATE INDEX IF NOT EXISTS ix_telegram_chats_link_status ON telegram_chats(link_status)");
    }

    public function down(): void
    {
        // Сначала снимем индекс и CHECK
        DB::statement("DROP INDEX IF EXISTS ix_telegram_chats_link_status");
        DB::statement("ALTER TABLE telegram_chats DROP CONSTRAINT IF EXISTS chk_telegram_chats_link_status");

        Schema::table('telegram_chats', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_chats', 'unlinked_at')) {
                $table->dropColumn('unlinked_at');
            }
            if (Schema::hasColumn('telegram_chats', 'linked_at')) {
                $table->dropColumn('linked_at');
            }
            if (Schema::hasColumn('telegram_chats', 'link_status')) {
                $table->dropColumn('link_status');
            }
        });
    }
};
