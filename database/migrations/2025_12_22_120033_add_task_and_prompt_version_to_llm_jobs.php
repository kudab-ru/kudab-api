<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_jobs', function (Blueprint $table) {
            $table->string('task', 64)->nullable()->index();
            $table->string('prompt_version', 32)->nullable()->index();
        });

        // Проверка дублей (на всякий, если кто-то уже руками добавлял)
        $dupe = DB::selectOne("
            select context_post_id, task, prompt_version, count(*) as cnt
            from llm_jobs
            where context_post_id is not null
              and task is not null
              and prompt_version is not null
            group by context_post_id, task, prompt_version
            having count(*) > 1
            limit 1
        ");

        if ($dupe) {
            throw new RuntimeException(sprintf(
                'Duplicate llm_jobs detected for context_post_id=%s task=%s prompt_version=%s. Deduplicate before applying unique index.',
                $dupe->context_post_id,
                $dupe->task,
                $dupe->prompt_version
            ));
        }

        // Частичный уникальный индекс
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY llm_jobs_ctx_task_prompt_uniq
            ON llm_jobs (context_post_id, task, prompt_version)
            WHERE context_post_id IS NOT NULL
              AND task IS NOT NULL
              AND prompt_version IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS llm_jobs_ctx_task_prompt_uniq");

        Schema::table('llm_jobs', function (Blueprint $table) {
            $table->dropIndex(['task']);
            $table->dropIndex(['prompt_version']);
            $table->dropColumn(['task', 'prompt_version']);
        });
    }
};
