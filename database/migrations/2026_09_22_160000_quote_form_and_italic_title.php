<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Третья форма поста — «Цитата», и курсивное название вместо жирного.
//
// Подсмотрено у московского агрегатора: название курсивом с тематическим
// значком, описание вынесено в цитату. Цитата отделяет ЧУЖОЙ голос (описание
// события) от нашего служебного текста — строк места, времени и цены.
//
// Значок 🎟 убран из всех форм: билет к содержанию отношения не имеет и стоял
// у каждого поста одинаково. На его месте {kind_emoji} — 🎭 у спектакля, 🎵 у
// концерта, 🖼 у выставки; незнакомая тема остаётся без значка.
return new class extends Migration
{
    private const QUOTE_CODE = 'quote';

    private const QUOTE_BODY = <<<'TXT'
<i>{title}</i> {kind_emoji}

📍 {address}
🗓 {start_time|human}
💸 {price_label}

<blockquote>{lead|slice:0..400|escape_html}</blockquote>

{more_link}          {original_link}
TXT;

    private const BASIC_BODY = <<<'TXT'
<i>{title}</i> {kind_emoji}
{lead|slice:0..400|escape_html}

📍 {address}
🗓 {start_time|human}
💸 {price_label}

{about|slice:0..400|escape_html}

{more_link}          {original_link}
TXT;

    private const LEAD_BELOW_BODY = <<<'TXT'
<i>{title}</i> {kind_emoji}

📍 {address}
🗓 {start_time|human}
💸 {price_label}

{lead|slice:0..400|escape_html}

{about|slice:0..400|escape_html}

{more_link}          {original_link}
TXT;

    public function up(): void
    {
        if (! DB::table('telegram.message_templates')->where('code', self::QUOTE_CODE)->exists()) {
            DB::table('telegram.message_templates')->insert([
                'code' => self::QUOTE_CODE,
                'locale' => 'ru',
                'name' => 'Анонс цитатой',
                'description' => 'Описание вынесено в цитату — отделяет чужой голос от наших строк.',
                'body' => self::QUOTE_BODY,
                'show_images' => true,
                'max_images' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('telegram.message_templates')->where('code', 'basic')
            ->update(['body' => self::BASIC_BODY, 'updated_at' => now()]);
        DB::table('telegram.message_templates')->where('code', 'lead-below')
            ->update(['body' => self::LEAD_BELOW_BODY, 'updated_at' => now()]);

        // Третью форму добавляем в чередование там, где оно уже включено.
        foreach (DB::table('telegram.chat_broadcasts')->get(['id', 'settings']) as $row) {
            $settings = is_string($row->settings) ? json_decode($row->settings, true) : (array) $row->settings;
            if (! is_array($settings) || empty($settings['template_rotation'])) {
                continue;
            }

            $rotation = (array) $settings['template_rotation'];
            if (in_array(self::QUOTE_CODE, $rotation, true)) {
                continue;
            }

            $settings['template_rotation'] = [...$rotation, self::QUOTE_CODE];
            DB::table('telegram.chat_broadcasts')->where('id', $row->id)->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('telegram.message_templates')->where('code', self::QUOTE_CODE)->delete();
    }
};
