<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // 1) Проверяем дубли (только там, где url не null, иначе Postgres уникальностью не спасёт)
        $dupe = DB::selectOne("
            select parent_type, parent_id, type, url, count(*) as cnt
            from attachments
            where url is not null
            group by parent_type, parent_id, type, url
            having count(*) > 1
            limit 1
        ");

        if ($dupe) {
            throw new RuntimeException(sprintf(
                'Duplicate attachments detected for parent_type=%s parent_id=%s type=%s url=%s. Deduplicate before applying unique index.',
                $dupe->parent_type,
                $dupe->parent_id,
                $dupe->type,
                $dupe->url
            ));
        }

        // 2) Уникальный индекс по полиморфной связи + type + url
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY attachments_parent_type_parent_id_type_url_uniq
            ON attachments (parent_type, parent_id, type, url)
            WHERE url IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS attachments_parent_type_parent_id_type_url_uniq");
    }
};
