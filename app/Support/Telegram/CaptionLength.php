<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Длина подписи так, как её считает Telegram.
 *
 * ДВЕ ОШИБКИ БЫЛИ СРАЗУ, и обе в одну сторону — «пост слишком длинный», когда
 * он нормальный:
 *
 *  1. СЧИТАЛИ РАЗМЕТКУ. Telegram меряет ГОТОВЫЙ текст: при `parse_mode=HTML`
 *     теги снимаются и превращаются в entities, а адрес ссылки в длину не
 *     входит вовсе. `<a href="https://kudab.ru/events/428735?utm_source=tg…">
 *     Честный</a>` весит семь символов, а не сто десять. У подборки с четырьмя
 *     ссылками разметка тянет на полторы тысячи при семистах видимых — и
 *     правка такого поста отбивалась ошибкой «длиннее 1024».
 *  2. СЧИТАЛИ СИМВОЛЫ, А НЕ ЕДИНИЦЫ UTF-16. Эмодзи вне базовой плоскости
 *     (🎵, 🖼) занимают у Telegram ДВЕ единицы. На посте с эмодзи в шапке
 *     расхождение небольшое, но оно всегда в сторону «влезет», а это худшая
 *     сторона: альбом молча отобьётся, и пост уйдёт голым текстом.
 */
final class CaptionLength
{
    /** Предел подписи к картинкам. Текстовое сообщение — 4096, но пост с фото упирается сюда. */
    public const LIMIT = 1024;

    /** Сколько единиц займёт подпись у Telegram. */
    public static function visible(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // UTF-16 единицы: ровно то, чем меряет Telegram.
        return (int) (strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
    }

    public static function fits(string $html, int $limit = self::LIMIT): bool
    {
        return self::visible($html) <= $limit;
    }
}
