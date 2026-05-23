<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Шаг 2 PR6 (Я.Афиша как источник events).
 *
 * context_posts.structured_meta jsonb NULL — слот для уже-нормализованной
 * микроразметки от структурированных источников: Я.Афиша кладёт сюда
 * `{json_ld: {...}, og: {...}}` (PR6 коммит 5), bypass-extractor читает
 * напрямую и формирует events-payload без вызова LLM. Я.Афиша-listing
 * в text — plain-описание из og:description, без JSON-блоков.
 *
 * Зачем колонка, а не markered-block в text:
 *   1) bench/llm-report-post не загромождаются сырым JSON;
 *   2) будущие structured-источники (ICS, RSS) пойдут тем же чистым путём;
 *   3) каст в массив в Eloquent — без regex-парсинга text'а.
 *
 * GIN-индекс НЕ создаём — на старте нет read-paths по structured_meta,
 * добавим точечно если появятся (например, поиск по json_ld.location.name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('context_posts', function (Blueprint $table) {
            $table->jsonb('structured_meta')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('context_posts', function (Blueprint $table) {
            $table->dropColumn('structured_meta');
        });
    }
};
