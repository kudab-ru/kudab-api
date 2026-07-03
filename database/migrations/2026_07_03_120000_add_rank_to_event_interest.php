<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Порядок тем события. Тэггер парсера ранжирует теги по уверенности
 * (InterestTagResolver: LLM-слаги → словарь по убыванию скора → floor),
 * но порядок терялся при записи, и API отдавал темы по interest_id —
 * «главной темой» почти всегда выходил зонтик с минимальным id (music).
 * rank = позиция тега в выдаче резолвера (0 = главная тема события).
 * Существующие строки получают rank=0 — до backfill-ретега порядок
 * прежний (id ASC как tie-break в relation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_interest', function (Blueprint $table) {
            $table->unsignedSmallInteger('rank')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('event_interest', function (Blueprint $table) {
            $table->dropColumn('rank');
        });
    }
};
