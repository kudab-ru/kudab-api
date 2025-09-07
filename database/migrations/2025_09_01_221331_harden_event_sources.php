<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function fkExists(string $table, string $constraint): bool
    {
        $sql = "
            SELECT 1
            FROM information_schema.table_constraints
            WHERE table_schema = 'public'
              AND table_name = ?
              AND constraint_name = ?
              AND constraint_type = 'FOREIGN KEY'
            LIMIT 1
        ";
        return (bool) DB::selectOne($sql, [$table, $constraint]);
    }

    public function up(): void
    {
        // 1) Добавим недостающие колонки (без ошибок, если уже есть)
        Schema::table('event_sources', function (Blueprint $t) {
            if (!Schema::hasColumn('event_sources', 'context_post_id')) {
                $t->unsignedBigInteger('context_post_id')->nullable()->after('social_link_id');
            }
            if (!Schema::hasColumn('event_sources', 'source')) {
                $t->string('source', 32)->nullable()->after('context_post_id');
            }
            if (!Schema::hasColumn('event_sources', 'post_external_id')) {
                $t->string('post_external_id', 128)->nullable()->after('source');
            }
            if (!Schema::hasColumn('event_sources', 'external_url')) {
                $t->string('external_url', 512)->nullable()->after('post_external_id');
            }
            if (!Schema::hasColumn('event_sources', 'published_at')) {
                // допускаем timestamp или timestamptz — тип не принципиален для этого харда
                $t->timestampTz('published_at')->nullable()->after('external_url');
            }
            if (!Schema::hasColumn('event_sources', 'images')) {
                $t->json('images')->nullable()->after('published_at');
            }
            if (!Schema::hasColumn('event_sources', 'meta')) {
                $t->json('meta')->nullable()->after('images');
            }
        });

        // 2) Индексы (idempotent)
        DB::statement("CREATE INDEX IF NOT EXISTS idx_event_sources_event_id ON event_sources (event_id)");
        // Частичный уникальный индекс под upsert по (source, post_external_id)
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_event_sources_source_post
            ON event_sources (source, post_external_id)
            WHERE post_external_id IS NOT NULL
        ");

        // 3) Внешние ключи — только если отсутствуют
        Schema::table('event_sources', function (Blueprint $t) {
            // пусто — сами FK добавим ниже условно
        });

        if (!$this->fkExists('event_sources', 'event_sources_context_post_id_foreign')
            && Schema::hasColumn('event_sources', 'context_post_id')) {
            Schema::table('event_sources', function (Blueprint $t) {
                $t->foreign('context_post_id')
                    ->references('id')->on('context_posts')
                    ->nullOnDelete();
            });
        }

        if (!$this->fkExists('event_sources', 'event_sources_social_link_id_foreign')
            && Schema::hasColumn('event_sources', 'social_link_id')) {
            Schema::table('event_sources', function (Blueprint $t) {
                $t->foreign('social_link_id')
                    ->references('id')->on('community_social_links')
                    ->cascadeOnDelete();
            });
        }

        if (!$this->fkExists('event_sources', 'event_sources_event_id_foreign')
            && Schema::hasColumn('event_sources', 'event_id')) {
            Schema::table('event_sources', function (Blueprint $t) {
                $t->foreign('event_id')
                    ->references('id')->on('events')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Сносим FK безопасно
        DB::statement("ALTER TABLE event_sources DROP CONSTRAINT IF EXISTS event_sources_context_post_id_foreign");
        DB::statement("ALTER TABLE event_sources DROP CONSTRAINT IF EXISTS event_sources_social_link_id_foreign");
        DB::statement("ALTER TABLE event_sources DROP CONSTRAINT IF EXISTS event_sources_event_id_foreign");

        // Сносим индексы безопасно
        DB::statement("DROP INDEX IF EXISTS uniq_event_sources_source_post");
        DB::statement("DROP INDEX IF EXISTS idx_event_sources_event_id");

        // Колонки не трогаем (миграция «harden»), чтобы не ломать старые данные
    }
};


// TODO: привести к виду
//
//use Illuminate\Database\Migrations\Migration;
//use Illuminate\Support\Facades\DB;
//
//return new class extends Migration
//{
//    // Нужен для CREATE INDEX CONCURRENTLY / DROP INDEX CONCURRENTLY
//    public bool $withinTransaction = false;
//
//    public function up(): void
//    {
//        DB::statement("
//            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uniq_event_sources_source_post
//            ON event_sources (source, post_external_id)
//            WHERE post_external_id IS NOT NULL
//        ");
//
//        DB::statement("
//            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_event_sources_event_id
//            ON event_sources (event_id)
//        ");
//    }
//
//    public function down(): void
//    {
//        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS uniq_event_sources_source_post");
//        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_event_sources_event_id");
//    }
//};
