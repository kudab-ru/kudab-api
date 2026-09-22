<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Тела шаблонов поста — в том виде, в каком они уходят в канал.
 *
 * ЭТОТ ФАЙЛ ОБЯЗАН СОВПАДАТЬ С ПОСЛЕДНЕЙ МИГРАЦИЕЙ ПО ШАБЛОНАМ. Тесты сидятся
 * им поверх миграций, то есть проверяют ИМЕННО эти строки. Пока он отставал,
 * тесты подтверждали текст, которого в канале уже не было: здесь ещё стоял
 * значок 🎟, убранный из всех форм, не было ни lead-below, ни quote, зато был
 * выключенный promo.
 */
class TelegramMessageTemplatesSeeder extends Seeder
{
    /**
     * A — текст НАД строками фактов.
     *
     * {text} — фраза модели, а если её нет, описание источника. Раньше здесь
     * стоял {lead}, а описание уезжало в подвал отдельным ключом, и форма A
     * совпадала с формой B байт в байт у 434 предстоящих событий из 449:
     * фраза модели есть всего у 15.
     */
    private const BASIC_BODY = <<<'TXT'
<b>{title}</b> {kind_emoji}

{text|sentence:400|escape_html}

📍 {address}
🗓️ {start_time|human}
{price_emoji} {price_label}

{more_link}          {original_link}
TXT;

    /** B — текст ПОД строками фактов. */
    private const LEAD_BELOW_BODY = <<<'TXT'
<b>{title}</b> {kind_emoji}

📍 {address}
🗓️ {start_time|human}
{price_emoji} {price_label}

{text|sentence:400|escape_html}

{more_link}          {original_link}
TXT;

    /**
     * C — текст в цитате.
     *
     * {quote} рендерится в целую цитату или в пустоту. Голый
     * <blockquote>{lead}</blockquote> давал ПУСТУЮ цитату у 97% событий:
     * полоска оставалась, текст пропадал.
     */
    private const QUOTE_BODY = <<<'TXT'
<b>{title}</b> {kind_emoji}

📍 {address}
🗓️ {start_time|human}
{price_emoji} {price_label}

{quote}

{more_link}          {original_link}
TXT;

    /**
     * Краткий: прозы нет ВООБЩЕ — короткий формат выбирают ровно за это.
     *
     * В чередование не входит, остаётся на случай, когда нужен голый анонс.
     */
    private const SHORT_BODY = <<<'TXT'
<b>{title}</b> {kind_emoji}

📍 {address}
🗓️ {start_time|human}
{price_emoji} {price_label}

{more_link}          {original_link}
TXT;

    public function run(): void
    {
        $now = Carbon::now();

        $rows = [
            [
                'code' => 'basic',
                'locale' => 'ru',
                'name' => 'Анонс с текстом сверху',
                'description' => 'Описание над строками места, времени и цены.',
                'body' => self::BASIC_BODY,
                'show_images' => true,
                'max_images' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'lead-below',
                'locale' => 'ru',
                'name' => 'Анонс с текстом снизу',
                'description' => 'Описание под строками места, времени и цены.',
                'body' => self::LEAD_BELOW_BODY,
                'show_images' => true,
                'max_images' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'quote',
                'locale' => 'ru',
                'name' => 'Анонс цитатой',
                'description' => 'Описание в цитате — отделяет чужой голос от наших строк.',
                'body' => self::QUOTE_BODY,
                'show_images' => true,
                'max_images' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'short',
                'locale' => 'ru',
                'name' => 'Краткий анонс',
                'description' => 'Компактный формат: заголовок, адрес, дата/время, цена и ссылка. Без прозы вовсе.',
                'body' => self::SHORT_BODY,
                'show_images' => true,
                'max_images' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
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
