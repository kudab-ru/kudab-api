<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Спрос из Яндекса: что ищут (`demand_signals`), с какими событиями это совпало
 * (`demand_matches`) и где владелец ответил «не то» (`demand_overrides`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_signals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id');
            // `webmaster`, `afisha_top` или `metrika`
            $table->string('source', 16);
            // Раздел Афиши; у других источников пустая строка, а не null: с null уникальность не сработает.
            $table->string('section', 32)->default('');
            // фраза запроса, адрес страницы Афиши или /events/{id}
            $table->string('topic', 500);
            $table->date('period_from');
            $table->date('period_to');
            // показы, место в топе или число людей — смотря по источнику
            $table->unsignedInteger('value');
            $table->jsonb('extra')->nullable();
            $table->timestampTz('collected_at');

            $table->foreign('city_id')->references('id')->on('cities')->cascadeOnDelete();
            $table->unique(['city_id', 'source', 'section', 'topic', 'period_to'], 'demand_signals_uq');
        });

        // Ключа на `events` нет: строки живут до следующего пересопоставления, а ключ
        // запирал бы таблицу событий на время миграции.
        Schema::create('demand_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('signal_id');
            // `series` — запрос про событие, `venue` — про площадку, тогда событие пустое
            $table->string('kind', 16);
            $table->unsignedBigInteger('event_id')->nullable();
            $table->unsignedBigInteger('event_group_id')->nullable();
            $table->unsignedBigInteger('venue_id')->nullable();
            $table->timestampTz('matched_at');

            $table->foreign('signal_id')->references('id')->on('demand_signals')->cascadeOnDelete();
            $table->index('signal_id');
        });

        Schema::create('demand_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id');
            // тема после нормализации: так ответ переживает смену недели
            $table->string('topic_key', 500);
            // `g:<event_group_id>`, у события без группы `e:<event_id>`
            $table->string('series_key', 32);
            $table->string('verdict', 16)->default('not_this');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('city_id')->references('id')->on('cities')->cascadeOnDelete();
            $table->unique(['city_id', 'topic_key', 'series_key'], 'demand_overrides_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_overrides');
        Schema::dropIfExists('demand_matches');
        Schema::dropIfExists('demand_signals');
    }
};
