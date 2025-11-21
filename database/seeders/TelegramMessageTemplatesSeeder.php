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

        // Базовый: заголовок, адрес/дата/цена, описание, теги, ссылка.
        $basicBody = implode("\n", [
            '🎟 <b>{title}</b>',
            '',
            '📍 {address}',
            '🗓 {start_time|human}',
            '💸 {price_label}',
            '',
            '{description|slice:0..400|escape_html}',
            '',
            '{tags|prepend:"🏷 "}',
            '',
            '<a href="{url}">Подробнее на kudasobrat.ru →</a>',
        ]);

        // Краткий: заголовок, адрес/дата/цена, теги, ссылка (без описания).
        $shortBody = implode("\n", [
            '🎟 <b>{title}</b>',
            '',
            '📍 {address}',
            '🗓 {start_time|human}',
            '💸 {price_label}',
            '',
            '{tags|prepend:"🏷 "}',
            '',
            '<a href="{url}">Подробнее на kudasobrat.ru →</a>',
        ]);

        // Промо: заголовок, адрес/дата/цена, короткое описание, теги, ссылка.
        $promoBody = implode("\n", [
            '🎟 <b>{title}</b>',
            '',
            '📍 {address}',
            '🗓 {start_time|human}',
            '💸 {price_label}',
            '',
            '{description|slice:0..280|escape_html}',
            '',
            '{tags|prepend:"🏷 "}',
            '',
            '<a href="{url}">Подробнее на kudasobrat.ru →</a>',
        ]);

        // --- Набор строк для upsert -----------------------------------------

        $rows = [
            [
                'code'        => 'basic',
                'locale'      => 'ru',
                'name'        => 'Базовый анонс',
                'description' => 'Полная карточка события: заголовок, адрес, дата/время, цена, теги, описание и ссылка.',
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
                'description' => 'Компактный формат: заголовок, адрес, дата/время, цена, теги и ссылка без описания.',
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
                'description' => 'Промо-формат: заголовок, адрес, дата/время, цена, короткое описание, теги и ссылка с брендом.',
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
