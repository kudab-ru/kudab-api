<?php

namespace App\Support\Telegram;

/**
 * Движок шаблонов подписи поста — перенос из бота, один в один.
 *
 * Оригинал: services/kudab-bot/app/bot/router/handlers/events.py:42-101.
 * Переносим побайтово, включая странности, а не «как правильно»: тексты уже
 * ушли подписчикам, и любое расхождение — это изменение того, что они увидят.
 * Исправлять поведение будем отдельно и осознанно, а не заодно с переездом.
 *
 * Синтаксис: {поле|фильтр:аргумент|фильтр2}. Пробелы вокруг частей срезаются.
 * Неизвестное поле даёт пустую строку, неизвестный фильтр — значение как есть.
 */
final class CaptionTemplate
{
    /** Ровно тот же регексп, что в боте: внутрь скобок вложенность не пускаем. */
    private const VAR_RE = '/\{([^{}]+)\}/u';

    /**
     * @param  array<string, mixed>  $ctx
     */
    public static function render(string $body, array $ctx): string
    {
        $out = preg_replace_callback(
            self::VAR_RE,
            static fn (array $m): string => self::resolve((string) $m[1], $ctx),
            $body,
        ) ?? $body;

        // Схлопываем три и более переводов строки в два. Именно это убирает
        // строки, которые «исчезли» вместе с пустым значением, — например
        // строку тегов, которая пуста ВСЕГДА (в боте ключа tags нет вовсе).
        return preg_replace('/\n{3,}/u', "\n\n", $out) ?? $out;
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private static function resolve(string $expr, array $ctx): string
    {
        $parts = array_map('trim', explode('|', $expr));
        $field = array_shift($parts) ?? '';

        $value = $ctx[$field] ?? '';
        $value = is_scalar($value) ? (string) $value : '';

        foreach ($parts as $filter) {
            $value = self::applyFilter($value, $filter);
        }

        return $value;
    }

    private static function applyFilter(string $value, string $filter): string
    {
        [$name, $arg] = array_pad(explode(':', $filter, 2), 2, null);
        $name = trim((string) $name);
        $arg = $arg !== null ? trim($arg) : null;

        return match ($name) {
            // Пустое значение съедает всю строку целиком — на этом держится
            // исчезновение строк с тегами и с ценой-заглушкой.
            'prepend' => $value === '' ? '' : self::unquote((string) $arg).$value,
            'slice' => self::slice($value, (string) $arg),
            // quote=false: кавычки НЕ трогаем, только & < >. Как в html.escape(..., quote=False).
            'escape_html' => htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            // В боте это заглушка, возвращающая значение как есть.
            'human' => $value,
            default => $value,
        };
    }

    private static function unquote(string $s): string
    {
        $len = mb_strlen($s);
        if ($len >= 2) {
            $first = mb_substr($s, 0, 1);
            $last = mb_substr($s, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return mb_substr($s, 1, $len - 2);
            }
        }

        return $s;
    }

    /**
     * slice:N..M — срез ПО СИМВОЛАМ (в Python строка юникодная, не байтовая),
     * поэтому mb_substr, а не substr: с substr русский текст резался бы посреди
     * буквы. Пустое N = 0, пустое M = до конца.
     *
     * Многоточие дописывается, только если срез реально что-то отрезал и хвост
     * ещё не заканчивается многоточием. Ровно 400 символов — без многоточия,
     * 401 — обрезка и «…».
     */
    private static function slice(string $value, string $arg): string
    {
        if (! preg_match('/^(\d*)\.\.(\d*)$/', $arg, $m)) {
            return $value;
        }

        $len = mb_strlen($value);
        $from = $m[1] === '' ? 0 : (int) $m[1];
        $to = $m[2] === '' ? $len : (int) $m[2];

        $out = mb_substr($value, $from, max(0, $to - $from));

        if ($to < $len && $out !== '' && ! str_ends_with($out, '…') && ! str_ends_with($out, '...')) {
            $out = rtrim($out)."\u{2026}";
        }

        return $out;
    }
}
