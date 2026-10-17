<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Название, написанное капсом, — к обычному виду: «Квиз "ХЭЛЛОУИН. ПРАЗДНИЧНАЯ ИГРА!"» → «Квиз "Хэллоуин.
 * Праздничная игра!"», одиночное слово — с заглавной. Короткие слова капсом — сокращения и названия групп
 * (МТС, ДДТ): без длинного слова рядом их не трогаем.
 */
final class CalmCaps
{
    public static function title(string $title): string
    {
        if (! preg_match('/\p{Lu}{5,}/u', $title)) {
            return $title;
        }

        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])\p{Lu}{2,}(?:[\s.,!?:;—–-]+\p{Lu}{2,})*(?![\p{L}\p{N}])/u',
            function (array $m) use ($title) {
                [$run, $at] = $m[0];
                if (! preg_match('/\p{Lu}{5,}/u', $run)) {
                    return $run;
                }

                $words = preg_split('/[\s.,!?:;—–-]+/u', trim($run), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $lower = mb_strtolower($run);
                // первое слово фразы с заглавной: в начале названия, после кавычки, скобки или точки
                $before = rtrim(substr($title, 0, $at));
                $opens = count($words) === 1 || $before === '' || preg_match('/[«"(\[.!?:]$/u', $before) === 1;
                $lower = (string) preg_replace_callback('/([.!?]\s+)(\p{Ll})/u', fn ($x) => $x[1].mb_strtoupper($x[2]), $lower);

                return $opens ? mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1) : $lower;
            },
            $title,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );
    }
}
