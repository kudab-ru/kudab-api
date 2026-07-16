<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «ты»-описание события для ТГ-анонса (единый голос с портретами площадок).
 *
 * Исходные `events.description` — на «вы»/нейтральные (извлечены из источника).
 * `tg_description` пишет парсер (parser:tg:event-describe) своими словами на «ты»;
 * бот предпочитает его в посте. `description` НЕ трогаем — его читает
 * EventBroadcastScorer (порог длины), поэтому отбор карточек не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->text('tg_description')->nullable()->after('short_description')
                ->comment('«ты»-описание для ТГ-анонса (parser:tg:event-describe); бот предпочитает его');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('tg_description');
        });
    }
};
