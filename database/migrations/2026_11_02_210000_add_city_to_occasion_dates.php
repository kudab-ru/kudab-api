<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Дата праздника может принадлежать городу: День города у каждого свой. Пустой город — дата для всех
 * городов, как у Ночи музеев. Какие праздники городские, знает конфиг повода (`per_city`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occasion_dates', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('slug')->constrained('cities')->cascadeOnDelete();
            $table->dropUnique(['slug', 'starts_on']);
            // без этого одна и та же общая дата вписалась бы дважды: пустые города база считает разными
            $table->unique(['slug', 'city_id', 'starts_on'])->nullsNotDistinct();
        });

        // уже вписанный День города — воронежский: другие города выключены
        $voronezh = DB::table('cities')->where('slug', 'voronezh')->value('id');
        if ($voronezh !== null) {
            DB::table('occasion_dates')->where('slug', 'povod-den-goroda')->whereNull('city_id')->update(['city_id' => $voronezh]);
        }
    }

    public function down(): void
    {
        Schema::table('occasion_dates', function (Blueprint $table) {
            $table->dropUnique(['slug', 'city_id', 'starts_on']);
            $table->dropConstrainedForeignId('city_id');
            $table->unique(['slug', 'starts_on']);
        });
    }
};
