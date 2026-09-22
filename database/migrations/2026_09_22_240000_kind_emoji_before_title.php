<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Значок темы — ПЕРЕД названием, а не после.
//
// ПОЧЕМУ ОДНА ПОЗИЦИЯ, А НЕ ДВЕ. Значок делает одну из двух работ: объявляет
// («Припять 36» — без него не понять, что это) или уточняет («Концерт дуэта
// саксофониста Максима Беженова» — формат назван, 🎷 добавляет жанр).
// Посчитано на живой базе: объявляют 284 значка из 309 (92%), уточняют 25
// (8%). Две позиции ради восьми процентов читатель прочтёт как случайность, а
// не как систему.
//
// ПОЧЕМУ ПЕРЕД. Значок после названия стоит на ПЛАВАЮЩЕМ месте: у короткого
// названия близко к краю, у длинного уезжает за экран. Перед названием он
// встаёт в одну вертикаль с 📍 🗓️ 💸 — пост получает левую рейку из четырёх
// значков вместо трёх и одного гуляющего. Плюс он попадает в начало
// уведомления и в превью списка чатов, где раньше стояло голое название.
//
// Пустой значок (тема названа словом в заголовке — 130 постов из 439) не
// оставляет пробела: чистка краёв строк живёт в CaptionTemplate::render.
return new class extends Migration
{
    private const FROM = '<b>{title}</b> {kind_emoji}';

    private const TO = '{kind_emoji} <b>{title}</b>';

    public function up(): void
    {
        self::swap(self::FROM, self::TO);
    }

    public function down(): void
    {
        self::swap(self::TO, self::FROM);
    }

    private static function swap(string $from, string $to): void
    {
        foreach (DB::table('telegram.message_templates')->get(['id', 'body']) as $row) {
            $body = (string) $row->body;
            if (! str_contains($body, $from)) {
                continue;
            }

            DB::table('telegram.message_templates')->where('id', $row->id)->update([
                'body' => str_replace($from, $to, $body),
                'updated_at' => now(),
            ]);
        }
    }
};
