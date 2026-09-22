<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Селектор представления U+FE0F у значка даты.
//
// 🗓 это U+1F5D3, а у этой кодовой точки presentation по умолчанию ТЕКСТОВЫЙ
// (Emoji_Presentation=No). Без U+FE0F часть клиентов рисует её чёрно-белым
// символом рядом с цветными 📍 и 💸 — столбец значков разъезжается по цвету.
// Проверено hexdump: в телах шаблонов стояло f0 9f 97 93, то есть без
// селектора.
//
// Тот же разбор у 🖼️ и 🏛️ — они живут в словаре тем, правятся кодом.
return new class extends Migration
{
    private const FROM = "\u{1F5D3} {start_time";

    private const TO = "\u{1F5D3}\u{FE0F} {start_time";

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
