<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Один значок 💸 на строке цены — 🆓 и 🤝 убраны.
//
// Идея была такая: бесплатное — самый сильный крючок в афише, пусть его
// выхватывают взглядом при листании. Померил — крючка не выходит.
//
// 1. СТРОКА ЦЕНЫ ПРИ ЛИСТАНИИ НЕ ВИДНА. В формах A и C текст события стоит
//    МЕЖДУ названием и блоком фактов, то есть строка цены уезжает под «ещё».
//    Значок, оправданный скоростью, работал бы один день из трёх.
// 2. РАЗЛИЧЕНИЕ УЖЕ ЕСТЬ, И ОНО СИЛЬНЕЕ ЗНАЧКА. «Бесплатно» против
//    «2800 ₽–5400 ₽» — это слово против цифр, разница видна мгновенно.
//    Значок повторял то, что строка и так говорит.
// 3. 🆓 — ЕДИНСТВЕННЫЙ ГЛИФ В ПОСТЕ С ЛАТИНСКИМИ БУКВАМИ. В русской ленте он
//    читается баннером, а не пометкой редактора.
//
// Заодно отвергнут вариант «· бесплатно ·» вместо всей строки: он ломает
// левую рейку 📍 🗓️ 💸 ровно на той строке, которую хочется находить
// взглядом, и делает из одного шаблона два структурно разных поста.
return new class extends Migration
{
    public function up(): void
    {
        self::swap('{price_emoji} {price_label}', "\u{1F4B8} {price_label}");
    }

    public function down(): void
    {
        self::swap("\u{1F4B8} {price_label}", '{price_emoji} {price_label}');
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
