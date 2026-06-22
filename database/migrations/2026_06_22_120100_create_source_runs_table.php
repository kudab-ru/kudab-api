<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал прогонов сбора внешних источников — данные для статус-блока админки.
 *
 * ok/failed/duration сейчас живут ТОЛЬКО в файловых логах horizon
 * (yandex-afisha:batch-done), для UI непригодны. Одна строка на прогон,
 * пишется парсером (PR3) на точке batch-done в try/finally (от зависших
 * status=running). Per-category счётчики не храним — восстановимы из
 * external_url-сегмента / structured_meta.json_ld @type.
 *
 * Владелец схемы — kudab-api (общая БД). Пишет parser, читает api (статус-эндпоинт).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_runs')) {
            return;
        }

        Schema::create('source_runs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('source_slug', 32);
            $table->string('city_slug', 64);

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // running | ok | failed
            $table->string('status', 16)->default('running');

            $table->unsignedInteger('urls_total')->default(0);
            $table->unsignedInteger('posts_ok')->default(0);
            $table->unsignedInteger('posts_failed')->default(0);

            $table->text('error_text')->nullable();

            $table->timestamps();

            $table->index(['source_slug', 'city_slug', 'started_at'], 'source_runs_slug_city_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_runs');
    }
};
