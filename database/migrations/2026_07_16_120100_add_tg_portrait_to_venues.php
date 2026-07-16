<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отдельная «живая» проза площадки для ТГ-портрета.
 *
 * venues.description — нейтральный текст для карточки на сайте. Для поста в ТГ
 * нужен чуть более разговорный тон, поэтому храним его отдельно (пишет парсер:
 * parser:tg:venue-portrait --save). Строку «ближайшее событие» + ссылки api
 * дособирает при постановке в очередь (они time-sensitive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->text('tg_portrait')->nullable()->after('description')
                ->comment('Живая проза площадки для ТГ-портрета (пишет parser:tg:venue-portrait)');
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn('tg_portrait');
        });
    }
};
