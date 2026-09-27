<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Имя площадки для постов канала без второго имени и города
 * («Arena Hall / Aura Night Club», «Новый театр | Воронеж»).
 */
final class VenueName
{
    /** Разделители, после которых идёт второе имя или город. */
    private const MARKS = [' | ', ' / ', ' — филиал'];

    /** разделитель ближе к началу — часть имени: «V | Concert Hall» не резать до «V» */
    private const MIN_HEAD = 3;

    public static function label(?string $raw): string
    {
        $name = trim((string) $raw);
        if ($name === '') {
            return '';
        }

        foreach (self::MARKS as $mark) {
            $at = mb_strpos($name, $mark);
            if ($at !== false && $at >= self::MIN_HEAD) {
                $name = trim(mb_substr($name, 0, $at));
            }
        }

        return $name;
    }
}
