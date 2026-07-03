<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Серийные метаданные группы (регулярные события PR3,
 * kudab-parser/docs/RECURRING_EVENTS_PLAN.md).
 *
 * series_kind — инференс по фактическим датам инстансов (daily/weekly/
 * repertoire/irregular, пишет ночной events:groups:infer-series --save);
 * series_meta — детали паттерна (first/last/unique_days/stable_time/dow);
 * next_at — материализованный ближайший будущий инстанс серии.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_groups', function (Blueprint $table) {
            $table->string('series_kind', 16)->nullable();
            $table->jsonb('series_meta')->nullable();
            $table->timestamp('next_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_groups', function (Blueprint $table) {
            $table->dropColumn(['series_kind', 'series_meta', 'next_at']);
        });
    }
};
