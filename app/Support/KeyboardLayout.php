<?php

namespace App\Support;

/**
 * Запрос, набранный в английской раскладке вместо русской: «yfcnjkrb» значит «настолки».
 */
final class KeyboardLayout
{
    // клавиши по порядку, нижний и верхний регистр одной клавиши дают одну русскую букву
    private const LATIN = 'qwertyuiop[]asdfghjkl;\'zxcvbnm,.`{}:"<>~';

    private const RUSSIAN = 'йцукенгшщзхъфывапролджэячсмитьбюёхъжэбюё';

    /** null, если в запросе есть русские буквы или латиницы меньше трёх букв: тогда это не опечатка раскладки. */
    public static function toRussian(string $text): ?string
    {
        $text = mb_strtolower(trim($text));
        if (preg_match('/\p{Cyrillic}/u', $text) || preg_match_all('/[a-z]/', $text) < 3) {
            return null;
        }

        $map = array_combine(mb_str_split(self::LATIN), mb_str_split(self::RUSSIAN));

        return implode('', array_map(fn (string $ch) => $map[$ch] ?? $ch, mb_str_split($text)));
    }
}
