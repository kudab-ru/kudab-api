<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Происхождение координаты события. Пишет только парсер (EventUpsertJob).
 *
 * Зачем: в таблице не было ничего о том, откуда взялась точка, а источников
 * у неё пять — микроразметка структурированного источника, ответ DaData,
 * HQ-адрес сообщества, координаты площадки по имени и cold-resolve. «Дом
 * подтверждён по ФИАС» и «LLM выдумала правдоподобный адрес» выглядели в
 * базе одинаково, поэтому массовая уборка шла вслепую и по всей таблице:
 * при разборе июльских ложных точек пришлось восстанавливать источник
 * эвристиками по форме адреса.
 *
 * geo_source — кто дал точку:
 *   pre_geo     — микроразметка источника (Я.Афиша, Qtickets)
 *   dadata_clean / dadata_suggest — геокодер по адресу (стандартизация
 *                 или, если она не разобрала строку, подсказка)
 *   hq          — HQ-адрес сообщества (фолбэк и апгрейд центроида)
 *   venue_name  — координаты площадки, найденной по имени
 *   venue_cold  — площадка, созданная cold-resolve
 * geo_precision — насколько точка точна: house | street | poi | locality.
 *
 * ADD COLUMN nullable без DEFAULT — таблицу не переписывает, на больших
 * events не блокирует. Заполнение существующих строк — отдельной командой
 * с чанкингом, не миграцией.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $t) {
            if (! Schema::hasColumn('events', 'geo_source')) {
                $t->string('geo_source', 24)->nullable();
            }
            if (! Schema::hasColumn('events', 'geo_precision')) {
                $t->string('geo_precision', 16)->nullable();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON COLUMN events.geo_source IS 'Откуда координата: pre_geo|dadata_clean|dadata_suggest|hq|venue_name|venue_cold'");
            DB::statement("COMMENT ON COLUMN events.geo_precision IS 'Точность координаты: house|street|poi|locality'");
        }
    }

    public function down(): void
    {
        foreach (['geo_source', 'geo_precision'] as $column) {
            if (Schema::hasColumn('events', $column)) {
                Schema::table('events', function (Blueprint $t) use ($column) {
                    $t->dropColumn($column);
                });
            }
        }
    }
};
