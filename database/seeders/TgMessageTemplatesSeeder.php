<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TgMessageTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Базовые шаблоны с «техническими» токенами {E_*}, чтобы одной заменой сделать
        // и обычные (unicode), и кастомные ({emoji:*}).
        $tpl = [
            // Короткий
            'short' => "{E_TICKET} <b>{title}</b>\n{start_time|human} · {city}\n\n<a href=\"{url}\">Подробнее →</a>",
            // Длинный — новая строка дат: 🗓 weekday · date · time
            'long'  => "{E_SPARK} <b>{title}</b>\n{E_CAL} {start_time|weekday} · {start_time|date} · {start_time|time}\n{E_PIN} {city}\n\n{description|slice:0..500|escape_html}\n\n<a href=\"{url}\">Подробнее →</a>",
            // Постер (короткая подпись для картинки)
            'poster'=> "{E_SPARK} <b>{title}</b>\n{E_CLOCK} {start_time|human} · {city}\n\n<a href=\"{url}\">Подробнее →</a>",
            // Дайджест (3 пункта)
            'digest'=> "{E_CAL} <b>Дайджест</b>\n"
                ."• {item1_title} — {item1_time|time} ({item1_city}) — <a href=\"{item1_url}\">читать</a>\n"
                ."• {item2_title} — {item2_time|time} ({item2_city}) — <a href=\"{item2_url}\">читать</a>\n"
                ."• {item3_title} — {item3_time|time} ({item3_city}) — <a href=\"{item3_url}\">читать</a>",
            // Напоминание
            'remind'=> "{E_BELL} <b>Уже скоро: {title}</b>\nСтарт в {start_time|time} ({start_time|date})\n\n<a href=\"{url}\">Подробнее →</a>",
        ];

        // Палитра для «обычных» шаблонов (unicode)
        $paletteUnicode = [
            '{E_TICKET}' => '🎫',
            '{E_SPARK}'  => '✨',
            '{E_CAL}'    => '🗓',
            '{E_CLOCK}'  => '🕒',
            '{E_PIN}'    => '📍',
            '{E_BELL}'   => '🔔',
        ];
        // Палитра для *_custom_ru — используем плейсхолдеры {emoji:*}
        $paletteCustom = [
            '{E_TICKET}' => '{emoji:ticket}',
            '{E_SPARK}'  => '{emoji:sparkles}',
            '{E_CAL}'    => '{emoji:calendar}',
            '{E_CLOCK}'  => '{emoji:clock}',
            '{E_PIN}'    => '{emoji:pin}',
            '{E_BELL}'   => '{emoji:bell}',
        ];

        $mk = function(array $tplSet, array $palette): array {
            $out = [];
            foreach ($tplSet as $k => $v) $out[$k] = strtr($v, $palette);
            return $out;
        };

        $plain  = $mk($tpl, $paletteUnicode);
        $custom = $mk($tpl, $paletteCustom);

        $rows = [
            // ===== обычные =====
            [
                'name' => 'short_ru', 'locale' => 'ru',
                'body_markdown' => $plain['short'],
                'show_images' => false, 'max_images' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'long_ru', 'locale' => 'ru',
                'body_markdown' => $plain['long'],
                'show_images' => false, 'max_images' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'poster_ru', 'locale' => 'ru',
                'body_markdown' => $plain['poster'],
                'show_images' => true, 'max_images' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'poster_long_ru', 'locale' => 'ru',
                'body_markdown' => $plain['long'],
                'show_images' => true, 'max_images' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'gallery_ru', 'locale' => 'ru',
                'body_markdown' => $plain['short'],
                'show_images' => true, 'max_images' => 3,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'digest_ru', 'locale' => 'ru',
                'body_markdown' => $plain['digest'],
                'show_images' => false, 'max_images' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'reminder_ru', 'locale' => 'ru',
                'body_markdown' => $plain['remind'],
                'show_images' => false, 'max_images' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],

            // ===== "кастомные" клоны (те же тела, но {emoji:*}) =====
            [
                'name' => 'short_custom_ru', 'locale' => 'ru',
                'body_markdown' => $custom['short'],
                'show_images' => false, 'max_images' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'long_custom_ru', 'locale' => 'ru',
                'body_markdown' => $custom['long'],
                'show_images' => false, 'max_images' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'poster_custom_ru', 'locale' => 'ru',
                'body_markdown' => $custom['poster'],
                'show_images' => true, 'max_images' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'poster_long_custom_ru', 'locale' => 'ru',
                'body_markdown' => $custom['long'],
                'show_images' => true, 'max_images' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'digest_custom_ru', 'locale' => 'ru',
                'body_markdown' => $custom['digest'],
                'show_images' => false, 'max_images' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'reminder_custom_ru', 'locale' => 'ru',
                'body_markdown' => $custom['remind'],
                'show_images' => false, 'max_images' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        // upsert по уникальному индексу name — id/ссылки на шаблоны и правила не трогаем
        DB::table('tg_message_templates')->upsert(
            $rows,
            ['name'],
            ['locale','body_markdown','show_images','max_images','updated_at']
        );
    }
}
