<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Неверный домен в шаблонах постов рассылки.
 *
 * В telegram.message_templates у всех трёх шаблонов (basic, promo, short) в
 * последней строке стоит «Подробнее на kudasobrat.ru →», хотя сайт — kudab.ru.
 * Сидер TelegramMessageTemplatesSeeder.php:29 всегда содержал правильный домен,
 * то есть живая база разошлась с ним где-то по дороге.
 *
 * Сейчас эту строку не видит никто: у всех 2305 событий заполнен external_url,
 * а при непустом canonical_url бот целиком подменяет последнюю строку своей
 * захардкоженной версией (events.py:190-220), где домен верный. Но стоит
 * появиться событию без внешней ссылки — и в канал уйдёт неверный адрес.
 *
 * Правим точечной заменой подстроки, а не перезаписью body целиком: тексты
 * шаблонов могли править руками, и терять эти правки нельзя.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('telegram.message_templates')
            ->where('body', 'like', '%kudasobrat.ru%')
            ->update([
                'body' => DB::raw("replace(body, 'kudasobrat.ru', 'kudab.ru')"),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Обратно не возвращаем: домен kudasobrat.ru неверен, и восстанавливать
        // его в текстах, которые уходят подписчикам, незачем.
    }
};
