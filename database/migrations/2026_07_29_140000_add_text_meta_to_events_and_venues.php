<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Следы генерации текстов: что, когда и какой моделью писали, и чем кончилось.
 * Пишет ТОЛЬКО парсер (App\Services\Text\TextProvenance), читает он же.
 *
 * Зачем: сегодня отбракованная генерация не оставляет следа, поэтому событие
 * снова попадает в выборку следующей ночью — и так все 60 дней до своей даты.
 * Платим за один и тот же неудачный текст десятки раз. Маркер закрывает эту дыру
 * и заодно отвечает на вопросы «каким промптом это написано» и «сколько текстов
 * ушло в каталог без опоры на факты».
 *
 * Формат — ключ на производителя:
 *   {"tg": {"v":"a1b2c3d4","model":"...","at":"...","r":"ok","h":"<sha1>","n":1,"tok":[512,118]},
 *    "clean": {...}, "enrich": {...}, "venue": {...}, "venue_long": {...}, "portrait": {...},
 *    "lock": ["description"]}
 * где v — версия промпта, h — sha1 текста, который мы видели, r — исход,
 * n — число попыток, tok — [вход, выход] по всем вызовам включая регенерации.
 *
 * Свежесть = (версия промпта совпала) И (sha1 совпал). Второе обязательно:
 * EventUpsertJob перезаписывает description более длинным входящим при каждом
 * новом посте, и без хеша грязное описание считалось бы «уже обработанным» навсегда.
 *
 * ADD COLUMN nullable без DEFAULT — не переписывает таблицу, на больших events
 * не блокирует. lock — единственный ключ, который ставит НЕ парсер: его пишет
 * админка при ручной правке текста, и парсер такие поля больше не трогает.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['events', 'venues'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'text_meta')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->jsonb('text_meta')->nullable();
                });
            }
        }

        // голос канала переезжает на «вы» — комментарии-контракты приводим в соответствие
        if (DB::getDriverName() === 'pgsql') {
            if (Schema::hasColumn('events', 'tg_description')) {
                DB::statement("COMMENT ON COLUMN events.tg_description IS 'Готовый анонс для ТГ-канала (на «вы»); бот предпочитает его description'");
            }
            if (Schema::hasColumn('venues', 'tg_portrait')) {
                DB::statement("COMMENT ON COLUMN venues.tg_portrait IS 'Проза портрета площадки для ТГ-канала (на «вы»)'");
            }
        }
    }

    public function down(): void
    {
        foreach (['events', 'venues'] as $table) {
            if (Schema::hasColumn($table, 'text_meta')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('text_meta');
                });
            }
        }
    }
};
