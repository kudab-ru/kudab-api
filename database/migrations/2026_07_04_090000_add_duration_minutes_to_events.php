<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Длительность события в минутах (глубина события: «продолжительность»).
 * Источник — структурная разметка (Я.Афиша JSON-LD duration=PT3H30M);
 * LLM-извлечение — отдельной промпт-версией позже. Отдельная колонка, а не
 * end_time: у репертуара/проката end_time несёт конец ДИАПАЗОНА показов,
 * а не конец сеанса (известная двусмысленность из TASKS «глубина события»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->smallInteger('duration_minutes')->unsigned()->nullable()->after('age_restriction');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
