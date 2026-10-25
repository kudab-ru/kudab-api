<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Как записаться на событие — строкой организатора из поста: «Запись обязательна: 8988-477-57-19 (Макс)».
 * Берём упоминание записи, рядом с которым есть телефон, ссылка или ник; «без записи» не в счёт. В посте
 * про несколько событий сначала ищем после названия своего: иначе возьмём запись соседнего.
 */
final class SignupNote
{
    private const MENTION = '~запис|регистрац|бронир~iu';

    private const NEGATED_BEFORE = '~(?:^|[^\p{L}])без(?:\s+предварительной)?\s*$~iu';

    private const NEGATED_AFTER = '~^\p{L}*\s+(?:не\s+(?:нужн|требу|обязат)|нет(?!\p{L}))~iu';

    private const PHONE = '~(?:\+7|(?<!\d)8)[\s\-(]*\d{3}[\s\-)]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}(?!\d)~u';

    private const LINK = '~https?://\S+|(?<![\w.@/])(?:vk\.(?:com|ru|me)|t\.me|forms\.yandex\.ru|forms\.gle)/\S+|(?<![\w.])@[A-Za-z0-9_]{4,}~u';

    /** Сколько байт после упоминания смотреть: около трёхсот букв, дальше в посте обычно уже другое. */
    private const WINDOW = 600;

    public static function from(?string $text, string $title = ''): ?string
    {
        $text = str_replace("\r\n", "\n", trim((string) $text));
        if ($text === '') {
            return null;
        }

        $from = self::titleOffset($text, $title);

        return self::scan($text, $from) ?? ($from > 0 ? self::scan($text, 0) : null);
    }

    private static function scan(string $text, int $from): ?string
    {
        preg_match_all(self::MENTION, $text, $m, PREG_OFFSET_CAPTURE, $from);
        foreach ($m[0] as [, $at]) {
            $headStart = max(0, $at - 160);
            // не mb_strcut: начав посреди буквы, он сдвигает и конец и теряет знак перед упоминанием
            $before = (string) preg_replace('~^[\x80-\xBF]+~', '', substr($text, $headStart, $at - $headStart));
            $after = mb_strcut($text, $at, self::WINDOW);
            if (preg_match(self::NEGATED_BEFORE, $before) || preg_match(self::NEGATED_AFTER, $after)) {
                continue;
            }

            // абзац после упоминания: за пустой строкой начинается другое
            $block = preg_split('~\n\s*\n~u', $after, 2)[0];
            $end = self::lastContactEnd($block);
            if ($end === null) {
                continue;
            }
            $lineEnd = strpos($block, "\n", $end);
            $tail = substr($block, 0, $lineEnd === false ? strlen($block) : $lineEnd);

            // начало предложения с упоминанием: «по предварительной записи» без начала теряет смысл
            $parts = preg_split('~(?:[.!?]\s+|\n)~u', $before);
            $head = (string) end($parts);
            if (count($parts) === 1 && $headStart > 0) {
                $head = (string) preg_replace('~^\S*\s+~u', '', $head);
            }
            $note = $head.$tail;

            $note = preg_replace(
                // разметка ВК: [club1|@ник] → @ник, [ссылка|текст] → ссылка
                ['~\[(?:club|public|id)\d+\|([^\]]+)\]~u', '~\[(https?://[^|\]\s]+)\|[^\]]*\]~u', '~([:.!?;—–-])\s*\n\s*~u', '~\s*\n\s*~u', '~\s+~u'],
                ['$1', '$1', '$1 ', ', ', ' '],
                $note,
            ) ?? $note;

            return trim((string) preg_replace('~^[^\p{L}\p{N}+@]+~u', '', $note));
        }

        return null;
    }

    private static function lastContactEnd(string $block): ?int
    {
        $end = null;
        foreach ([self::PHONE, self::LINK] as $pattern) {
            if (preg_match_all($pattern, $block, $m, PREG_OFFSET_CAPTURE)) {
                $last = end($m[0]);
                $end = max($end ?? 0, $last[1] + strlen($last[0]));
            }
        }

        return $end;
    }

    /** Байтовое место названия события в тексте; 0 — не нашлось. */
    private static function titleOffset(string $text, string $title): int
    {
        $title = trim($title);
        if ($title === '') {
            return 0;
        }
        $pos = mb_stripos($text, $title);

        return $pos === false ? 0 : strlen(mb_substr($text, 0, $pos));
    }
}
