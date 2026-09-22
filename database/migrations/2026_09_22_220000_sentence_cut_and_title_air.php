<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Срез текста по границе ФРАЗЫ и воздух под названием.
//
// СРЕЗ. `slice:0..400` рубил по счётчику символов и попадал в середину слова:
// из 193 предстоящих событий с текстом длиннее 400 знаков так обрывались 187,
// то есть 97%. В ленте это выглядело как «объединил более 2000 школьников и
// 428 ком…» — главный признак машины, человек так не пишет. Фильтр
// `sentence:400` режет по последнему концу предложения, который помещается в
// лимит; многоточие не дописывается — текст просто кончается законченной
// мыслью, а что он не весь, говорит ссылка «Подробнее». Медиана потери 74
// знака, обрывов посреди фразы стало 0 из 193.
//
// ВОЗДУХ. В форме A текст шёл вплотную под названием, без пустой строки.
// Задумано это было под живую фразу модели — короткий крючок второй строкой.
// Но фраза есть у 15 событий из 449, а у остальных под названием встаёт
// пресс-релиз на три абзаца, и длинное название сливается с ним в стену.
// В формах B и C пустая строка под названием и так стоит — теперь все три
// открываются одинаково.
return new class extends Migration
{
    private const BASIC_BODY = <<<'TXT'
<b>{title}</b> {kind_emoji}

{text|sentence:400|escape_html}

📍 {address}
🗓 {start_time|human}
{price_emoji} {price_label}

{more_link}          {original_link}
TXT;

    private const LEAD_BELOW_BODY = <<<'TXT'
<b>{title}</b> {kind_emoji}

📍 {address}
🗓 {start_time|human}
{price_emoji} {price_label}

{text|sentence:400|escape_html}

{more_link}          {original_link}
TXT;

    public function up(): void
    {
        DB::table('telegram.message_templates')->where('code', 'basic')
            ->update(['body' => self::BASIC_BODY, 'updated_at' => now()]);
        DB::table('telegram.message_templates')->where('code', 'lead-below')
            ->update(['body' => self::LEAD_BELOW_BODY, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Возврата нет намеренно: прежний срез рвал слово у 97% длинных
        // текстов.
    }
};
