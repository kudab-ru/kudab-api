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

    /** Короче — город не снимаем: от «Воронежский дом» остался бы «Дом». */
    private const MIN_REST = 4;

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

        // из разметки сайтов имя приходит с экранированными кавычками: Бар \"Цензура\"
        $name = str_replace('\\"', '"', $name);

        return (string) preg_replace('/"([^"]+)"/u', '«$1»', $name);
    }

    /**
     * Имя без города, который канал и так называет: «Воронежский камерный театр» — «Камерный театр»,
     * «Музей И.А. Бунина в Воронеже» — «Музей И.А. Бунина». Прежнее имя в скобках («ex. Event Hall») тоже лишнее.
     */
    public static function withoutCity(string $name, string $city, ?string $cityIn = null): string
    {
        $out = trim((string) preg_replace('/\s*\((?:ex|бывш|ранее)\.?\s[^)]*\)/iu', '', $name));
        $c = preg_quote(trim($city), '/');
        if ($c === '') {
            return $out;
        }

        // «Hobby Games — Воронеж — Настольные игры»
        $out = trim((string) preg_replace('/\s+[—–-]\s+'.$c.'(?=\s+[—–-]\s)/iu', '', $out));
        // в конце города нет, только если перед ним больше одного слова: «Кинотеатр Воронеж» — это имя
        $in = $cityIn !== null && $cityIn !== '' ? '|\s+в\s+'.preg_quote($cityIn, '/') : '';
        $tail = trim((string) preg_replace('/(?:\s+(?:г\.\s*)?'.$c.$in.')$/iu', '', $out));
        if ($tail !== $out && preg_match('/\S\s+\S/u', $tail) === 1) {
            $out = $tail;
        }
        $rest = trim((string) preg_replace('/^'.$c.'(?:ск)?(?:ий|ая|ое|ие)\s+/iu', '', $out));
        if ($rest !== $out && mb_strlen($rest) >= self::MIN_REST) {
            $out = mb_strtoupper(mb_substr($rest, 0, 1)).mb_substr($rest, 1);
        }

        return $out;
    }
}
