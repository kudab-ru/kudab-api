<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Очередь доменов-кандидатов в источники (само-строящийся парсер, интейк).
//
// Механика подключения сайтов готова целиком — probe, self-heal, карантин,
// онбординг в админке. Не готов был ПЕРВЫЙ шаг: домены искала read-only
// команда parser:sources:candidates, которой не было в расписании, и владелец
// нигде не видел список «что стоит подключить». Таблица закрывает этот разрыв:
// ночной прогон складывает сюда результат, админка показывает очередь.
//
// Таблица — кэш отчёта, а не источник правды: её можно очистить, следующий
// прогон наполнит заново. Единственное, что нельзя терять, — решения
// владельца (dismissed_at), поэтому строки обновляются по домену, а не
// пересоздаются.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('sample_url', 500)->nullable();
            // число сообществ, ссылающихся на домен — главный сигнал ранга
            $table->unsignedInteger('communities')->default(0);
            $table->unsignedInteger('posts')->default(0);
            // вердикт проверки Event JSON-LD на sample-URL: jsonld | none | error
            $table->string('verdict', 20)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // решение владельца «это не афиша» — переживает пересчёты
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['dismissed_at', 'communities']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_candidates');
    }
};
