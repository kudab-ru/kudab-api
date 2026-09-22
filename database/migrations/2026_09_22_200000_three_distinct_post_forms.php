<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Три формы поста, которые ДЕЙСТВИТЕЛЬНО разные, и значок для строки цены.
//
// ЧТО БЫЛО СЛОМАНО. Формы basic и lead-below задумывались как «описание
// сверху» и «описание снизу», но обе опирались на {lead} — фразу, которую
// пишет модель. Она есть у 15 предстоящих событий из 449. У остальных 434
// {lead} пуст, заполняется {about}, а он в ОБЕИХ формах стоял внизу — и посты
// выходили байт в байт одинаковыми. Чередование по дням крутило одно и то же.
//
// Форма quote была хуже: <blockquote>{lead}</blockquote> без фразы модели
// давала ПУСТУЮ цитату — текст события пропадал целиком, оставалась полоска.
//
// ЧТО СТАЛО. Общий ключ {text} — фраза модели, а если её нет, описание
// источника; и готовый {quote}, который рендерится в цитату или в пустоту.
// Теперь формы различаются местом текста, а не наличием поля:
//   A basic      — текст НАД строками фактов
//   B lead-below — текст ПОД строками фактов
//   C quote      — текст в цитате под фактами
//
// ЗНАЧОК ЦЕНЫ. Было жёстко зашитое 💸 у всех. У бесплатного события значок
// денег читается ошибкой, а бесплатное — самый сильный крючок в афише.
// {price_emoji} даёт 🆓 бесплатно, 🤝 донат, 💸 остальное.
//
// КУРСИВА НЕТ НИГДЕ. Разбор четырьмя углами дал единогласное «нет» курсиву на
// фактах: значки 📍🗓💸 и пустая строка уже отделяют блок, а курсивная
// кириллица в Телеграме — узкое наклонное начертание, на котором «19:00» и
// «2100 ₽–6100 ₽» теряют чёткость ровно там, где за них цепляется взгляд.
// Курсив на описании тоже отброшен: в форме C ту же работу делает цитата, а в
// A и B — позиция текста. Второй способ сказать то же самое и есть шум.
return new class extends Migration
{
    private const BASIC_BODY = <<<'TXT'
<b>{title}</b> {kind_emoji}
{text|slice:0..400|escape_html}

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

{text|slice:0..400|escape_html}

{more_link}          {original_link}
TXT;

    private const QUOTE_BODY = <<<'TXT'
<b>{title}</b> {kind_emoji}

📍 {address}
🗓 {start_time|human}
{price_emoji} {price_label}

{quote}

{more_link}          {original_link}
TXT;

    private const BODIES = [
        'basic' => self::BASIC_BODY,
        'lead-below' => self::LEAD_BELOW_BODY,
        'quote' => self::QUOTE_BODY,
    ];

    public function up(): void
    {
        foreach (self::BODIES as $code => $body) {
            DB::table('telegram.message_templates')->where('code', $code)->update([
                'body' => $body,
                'updated_at' => now(),
            ]);
        }

        DB::table('telegram.message_templates')->where('code', 'basic')->update([
            'description' => 'Описание над строками места, времени и цены.',
        ]);
        DB::table('telegram.message_templates')->where('code', 'lead-below')->update([
            'description' => 'Описание под строками места, времени и цены.',
        ]);
        DB::table('telegram.message_templates')->where('code', 'quote')->update([
            'description' => 'Описание в цитате — отделяет чужой голос от наших строк.',
        ]);
    }

    public function down(): void
    {
        // Возврата к прежним телам нет намеренно: они давали два одинаковых
        // поста из трёх и пустую цитату. Откат вернул бы дефект.
    }
};
