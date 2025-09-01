<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Колонка dedup_key (34: 'e:' + md5)
        if (!Schema::hasColumn('events', 'dedup_key')) {
            Schema::table('events', function (Blueprint $table) {
                $table->string('dedup_key', 34)->nullable()->after('external_url');
            });
        } else {
            // Нормализуем тип (на случай старой длины)
            DB::statement("ALTER TABLE events ALTER COLUMN dedup_key TYPE VARCHAR(34)");
        }

        // 2) Проставляем dedup_key там, где NULL
        DB::statement("
            UPDATE events
               SET dedup_key = 'e:' || md5(
                    community_id::text || '|' ||
                    lower(coalesce(title,'')) || '|' ||
                    to_char(date_trunc('minute', start_time), 'YYYY-MM-DD HH24:MI') || '|' ||
                    lower(coalesce(city,'')) || '|' ||
                    lower(coalesce(address,''))
               )
             WHERE dedup_key IS NULL
        ");

        // 3) Схлопываем дубликаты среди активных (оставляем по одной):
        // приоритет: есть location → свежее updated_at → больший id.
        $order = Schema::hasColumn('events', 'location')
            ? "(location IS NOT NULL) DESC, updated_at DESC, id DESC"
            : "updated_at DESC, id DESC";

        DB::statement("
            WITH ranked AS (
              SELECT id, dedup_key,
                     ROW_NUMBER() OVER (PARTITION BY dedup_key ORDER BY {$order}) AS rn
              FROM events
              WHERE dedup_key IS NOT NULL AND deleted_at IS NULL
            )
            UPDATE events e
               SET deleted_at = NOW()
              FROM ranked r
             WHERE e.id = r.id AND r.rn > 1
        ");

        // 4) Уникальный частичный индекс по активным
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_events_dedup_active
              ON events (dedup_key)
              WHERE deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        if (Schema::hasTable('events')) {
            DB::statement("DROP INDEX IF EXISTS uniq_events_dedup_active");
            if (Schema::hasColumn('events', 'dedup_key')) {
                Schema::table('events', function (Blueprint $table) {
                    $table->dropColumn('dedup_key');
                });
            }
        }
    }
};
