<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Postgres: CREATE/DROP INDEX CONCURRENTLY нельзя внутри транзакции.
     * Laravel по умолчанию запускает миграции в транзакции -> выключаем.
     */
    public bool $withinTransaction = false;

    public function up(): void
    {
        // 1) Защитная проверка: если уже есть дубли — миграция падает с понятным сообщением
        $dupe = DB::selectOne("
            select source, external_id, social_link_id, count(*) as cnt
            from context_posts
            where social_link_id is not null
            group by source, external_id, social_link_id
            having count(*) > 1
            limit 1
        ");

        if ($dupe) {
            throw new RuntimeException(sprintf(
                'Duplicate context_posts rows detected for source=%s external_id=%s social_link_id=%s. Deduplicate before applying unique index.',
                $dupe->source,
                $dupe->external_id,
                $dupe->social_link_id
            ));
        }

        // 2) Частичный уникальный индекс только для строк с social_link_id IS NOT NULL
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY ctx_posts_src_ext_social_uniq
            ON context_posts (source, external_id, social_link_id)
            WHERE social_link_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS ctx_posts_src_ext_social_uniq");
    }
};
