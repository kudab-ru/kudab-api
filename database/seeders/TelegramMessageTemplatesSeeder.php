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

        // Базовый: заголовок, живая фраза, адрес/дата/цена, описание, ссылка.
        //
        // {lead} — анонс, написанный моделью, и он стоит ВТОРОЙ строкой, а не
        // в подвале: это единственное в посте, что написано словами. Пуст,
        // когда анонса нет, и тогда пост выглядит ровно как раньше. {about} —
        // пресс-релиз источника, и он зеркально молчит, когда анонс есть:
        // иначе один текст ушёл бы в пост дважды. Разводит их
        // [[EventCaptionBuilder]].
        //
        // Строки тегов здесь нет: ключ `tags` пуст всегда, и строка «🏷 …» не
        // напечаталась ни в одном посте за всё время.
        $basicBody = implode("\n", [
            '🎟 <b>{title}</b>',
            '{lead|slice:0..400|escape_html}',
            '',
            '📍 {address}',
            '🗓 {start_time|human}',
            '💸 {price_label}',
            '',
            '{about|slice:0..400|escape_html}',
            '',
            // Ссылки — плейсхолдерами, а не тегом руками: {original_link} исчезает
            // целиком у события без источника, а голый {canonical_url} в теге
            // оставил бы пустой href. И редактор шаблонов теперь показывает
            // ровно то, что уйдёт в канал: раньше вторую ссылку дописывал код
            // уже после рендера, и в шаблоне её не было видно.
            '{more_link}          {original_link}',
        ]);

        // Краткий: заголовок, адрес/дата/цена, ссылка. Прозы нет ВООБЩЕ —
        // ни анонса, ни описания: короткий формат выбирают ровно за это,
        // поэтому {lead} сюда не добавлен.
        $shortBody = implode("\n", [
            '🎟 <b>{title}</b>',
            '',
            '📍 {address}',
            '🗓 {start_time|human}',
            '💸 {price_label}',
            '',
            // Ссылки — плейсхолдерами; почему — у шаблона basic выше.
            '{more_link}          {original_link}',
        ]);

        // Промо: то же, что базовый, но короче — 280 знаков вместо 400.
        $promoBody = implode("\n", [
            '🎟 <b>{title}</b>',
            '{lead|slice:0..280|escape_html}',
            '',
            '📍 {address}',
            '🗓 {start_time|human}',
            '💸 {price_label}',
            '',
            '{about|slice:0..280|escape_html}',
            '',
            // Ссылки — плейсхолдерами; почему — у шаблона basic выше.
            '{more_link}          {original_link}',
        ]);

        // --- Набор строк для upsert -----------------------------------------

        $rows = [
            [
                'code'        => 'basic',
                'locale'      => 'ru',
                'name'        => 'Базовый анонс',
                'description' => 'Полная карточка события: заголовок, живая фраза под ним, адрес, дата/время, цена, описание и ссылка.',
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
                'description' => 'Компактный формат: заголовок, адрес, дата/время, цена и ссылка. Без прозы вовсе.',
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
                'description' => 'Промо-формат: то же, что базовый, но проза обрезается на 280 знаках.',
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
