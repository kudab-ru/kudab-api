<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Длина подписи так, как её считает Telegram: текст без тегов (href не в счёт)
 * и в единицах UTF-16, эмодзи вне BMP весят две.
 */
final class CaptionLength
{
    /** Предел подписи к картинкам. Текстовое сообщение — 4096, но пост с фото упирается сюда. */
    public const LIMIT = 1024;

    public static function visible(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (int) (strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
    }

    public static function fits(string $html, int $limit = self::LIMIT): bool
    {
        return self::visible($html) <= $limit;
    }
}
