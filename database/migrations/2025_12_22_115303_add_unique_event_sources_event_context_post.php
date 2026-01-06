<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{

    public function up(): void
    {
        // 1) Проверка дублей (только где context_post_id заполнен)
        $dupe = DB::selectOne("
            select event_id, context_post_id, count(*) as cnt
            from event_sources
            where context_post_id is not null
            group by event_id, context_post_id
            having count(*) > 1
            limit 1
        ");

        if ($dupe) {
            throw new RuntimeException(sprintf(
                'Duplicate event_sources detected for event_id=%s context_post_id=%s. Deduplicate before applying unique index.',
                $dupe->event_id,
                $dupe->context_post_id
            ));
        }

        // 2) Partial unique index: один post -> один source в рамках одного event
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY event_sources_event_context_post_uniq
            ON event_sources (event_id, context_post_id)
            WHERE context_post_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS event_sources_event_context_post_uniq");
    }
};
