<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TelegramMessageTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // --- Тела шаблонов ---------------------------------------------------

        // Полная карточка: заголовок, дата, город/площадка, короткое описание, ссылка
        $basicBody = implode("\n", [
            '<b>{title}</b>',
            '🗓 {start_time|human}',
            '📍 {city}{place|prepend:", "}',
            '',
            '{description|slice:0..400|escape_html}',
            '',
            '<a href="{url}">Подробнее →</a>',
        ]);

        // Компактный вариант: без описания, только факт + ссылка
        $shortBody = implode("\n", [
            '<b>{title}</b>',
            '🗓 {start_time|human} · {city}{place|prepend:", "}',
            '',
            '{url}',
        ]);

        // Более «промо»-вариант: крючок + укороченное описание + брендовая подпись
        $promoBody = implode("\n", [
            '🔥 <b>{title}</b>',
            '🗓 {start_time|human}',
            '📍 {city}{place|prepend:", "}',
            '',
            '{description|slice:0..280|escape_html}',
            '',
            '<a href="{url}">Смотреть на kudasobrat.ru →</a>',
        ]);

        // --- Набор строк для upsert -----------------------------------------

        $rows = [
            [
                'code'        => 'basic',
                'locale'      => 'ru',
                'name'        => 'Базовый анонс',
                'description' => 'Полная карточка события: заголовок, дата/город, короткое описание и ссылка.',
                'body'        => $basicBody,
                'show_images' => true,
                'max_images'  => 3,
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'code'        => 'short',
                'locale'      => 'ru',
                'name'        => 'Краткий анонс',
                'description' => 'Компактный формат: заголовок, дата/город и ссылка без описания.',
                'body'        => $shortBody,
                'show_images' => true,
                'max_images'  => 1,
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'code'        => 'promo',
                'locale'      => 'ru',
                'name'        => 'Промо-анонс',
                'description' => 'Чуть более “рекламный” формат: акцент, короткое описание и ссылка с брендом.',
                'body'        => $promoBody,
                'show_images' => true,
                'max_images'  => 3,
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ];

        // upsert по (code, locale), чтобы не ломать существующие ID
        DB::table('telegram.message_templates')->upsert(
            $rows,
            ['code', 'locale'],
            ['name', 'description', 'body', 'show_images', 'max_images', 'is_active', 'updated_at'],
        );
    }
}
