<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Юридическая возрастная маркировка: 0/6/12/16/18 (число до «+»).
            // Отдельная ось от audience (целевая аудитория) и audience_age_min/max
            // (заявленный возрастной диапазон). Извлекается парсером регуляркой из
            // description/title (EventTaxonomy::extractAgeRestriction). NULL = маркер
            // не найден. nullable smallInteger — как audience_age_min; PG11+ добавляет
            // nullable-колонку метаданными, без переписывания большой таблицы.
            if (!Schema::hasColumn('events', 'age_restriction')) {
                $table->unsignedSmallInteger('age_restriction')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'age_restriction')) {
                $table->dropColumn('age_restriction');
            }
        });
    }
};
