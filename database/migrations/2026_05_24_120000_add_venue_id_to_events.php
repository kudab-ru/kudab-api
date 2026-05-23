<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Венчающая миграция backlog-задачи venue_hosts (PR3).
 *
 * events.venue_id — связь event → venue (N:1). FK ON DELETE SET NULL,
 * чтобы удаление venue не каскадно дропало events: они должны выжить
 * с venue_id=NULL (резолвер при следующем upsert либо перепривяжет, либо
 * оставит NULL).
 *
 * Backfill из communities.venue_id one-shot SQL'ом: на текущем dev-снимке
 * (Воронеж, 15 venues × десятки events на каждый) закрывает 90%+ events
 * без обращения к VenueResolver. Прод-объёмы — десятки тысяч events,
 * JOIN по индексированному community_id — секунды.
 *
 * partial-index по venue_id IS NOT NULL: оптимизирует «события площадки»
 * запросы, не раздувает индекс рядами без venue_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('venue_id')->nullable()->after('community_id');

            $table->foreign('venue_id')
                ->references('id')->on('venues')
                ->onDelete('set null');
        });

        // Partial-index: попадание только по событиям с venue_id.
        DB::statement('CREATE INDEX events_venue_idx ON events (venue_id) WHERE venue_id IS NOT NULL');

        // Backfill: events.venue_id ← communities.venue_id. Idempotent
        // (повторный прогон обновляет только NULL-строки, не downgrade'ит).
        DB::statement(<<<'SQL'
UPDATE events
SET venue_id = c.venue_id, updated_at = NOW()
FROM communities c
WHERE events.community_id = c.id
  AND c.venue_id IS NOT NULL
  AND events.venue_id IS NULL
SQL);
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['venue_id']);
        });

        DB::statement('DROP INDEX IF EXISTS events_venue_idx');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('venue_id');
        });
    }
};
