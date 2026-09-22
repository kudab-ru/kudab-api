<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Вторая форма поста и чередование форм.
//
// Владелец 22.09.2026: «чередовать сейчас есть минимум описание сверху с
// описанием после инфы о мероприятии». Это правда два разных способа чтения:
// в первом сначала читается СОБЫТИЕ, во втором — «где и когда».
//
// Прежние три шаблона чередовать было нечем: basic, promo и short отличаются
// только степенью обрезки — это одна форма в трёх длинах, и читатель увидел бы
// не разнообразие, а пропавший текст.
//
// Тело новой формы повторяет basic, но живая фраза стоит ПОСЛЕ сведений.
return new class extends Migration
{
    private const CODE = 'lead-below';

    private const BODY = <<<'TXT'
🎟 <b>{title}</b>

📍 {address}
🗓 {start_time|human}
💸 {price_label}

{lead|slice:0..400|escape_html}

{about|slice:0..400|escape_html}

{more_link}          {original_link}
TXT;

    public function up(): void
    {
        $exists = DB::table('telegram.message_templates')->where('code', self::CODE)->exists();

        if (! $exists) {
            DB::table('telegram.message_templates')->insert([
                'code' => self::CODE,
                'locale' => 'ru',
                'name' => 'Анонс: описание после сведений',
                'description' => 'Название, место, время, цена — и только потом живая фраза.',
                'body' => self::BODY,
                'show_images' => true,
                'max_images' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Включаем чередование там, где форма ещё не выбиралась вручную.
        foreach (DB::table('telegram.chat_broadcasts')->get(['id', 'settings']) as $row) {
            $settings = is_string($row->settings) ? json_decode($row->settings, true) : (array) $row->settings;
            $settings = is_array($settings) ? $settings : [];

            if (! empty($settings['template_rotation'])) {
                continue;
            }

            $settings['template_rotation'] = [$settings['template_code'] ?? 'basic', self::CODE];

            DB::table('telegram.chat_broadcasts')->where('id', $row->id)->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('telegram.chat_broadcasts')->get(['id', 'settings']) as $row) {
            $settings = is_string($row->settings) ? json_decode($row->settings, true) : (array) $row->settings;
            if (! is_array($settings) || ! isset($settings['template_rotation'])) {
                continue;
            }

            unset($settings['template_rotation']);

            DB::table('telegram.chat_broadcasts')->where('id', $row->id)->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        DB::table('telegram.message_templates')->where('code', self::CODE)->delete();
    }
};
