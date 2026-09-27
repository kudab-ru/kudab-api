<?php

namespace App\Support\Telegram;

/**
 * Шаблоны подписи: {поле|фильтр:аргумент|фильтр2}. Неизвестное поле — пустая строка,
 * неизвестный фильтр — значение как есть. В боте своя копия движка
 * (render_message_template в handlers/events.py), без sentence и чистки краёв строк.
 */
final class CaptionTemplate
{
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

        // пустой плейсхолдер оставляет пробел на краю строки («{kind_emoji} <b>{title}</b>»);
        // срежется и отступ слева, если его поставить в шаблоне
        $out = preg_replace('/^[ \t]+|[ \t]+$/mu', '', $out) ?? $out;

        // строка пустого плейсхолдера пропадает, только если рядом уже есть пустая строка
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
            'prepend' => $value === '' ? '' : self::unquote((string) $arg).$value,
            'slice' => self::slice($value, (string) $arg),
            'sentence' => self::sentence($value, (int) $arg),
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
     * Обрезка по концу последнего предложения, что влезает в $max символов. Многоточие
     * не ставим: что текст не весь, говорит ссылка «Подробнее». Без точки в лимите —
     * по границе слова и с «…».
     */
    public static function sentence(string $value, int $max): string
    {
        $value = trim($value);
        if ($max <= 0 || mb_strlen($value) <= $max) {
            return $value;
        }

        $head = mb_substr($value, 0, $max);

        // точка без пробела после неё — сокращение или адрес сайта, не конец фразы
        if (preg_match_all('~[.!?…](?=[\s«"(]|$)~u', $head, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            // preg_* даёт смещение в байтах, а режем по символам
            $chars = mb_strlen(substr($head, 0, (int) $last[1])) + 1;
            $cut = trim(mb_substr($value, 0, $chars));
            if ($cut !== '') {
                return $cut;
            }
        }

        $space = mb_strrpos($head, ' ');
        $cut = $space === false ? $head : mb_substr($head, 0, $space);

        return rtrim($cut, ' ,;:—-')."\u{2026}";
    }

    /** slice:N..M по символам, пустое N — с начала, пустое M — до конца */
    public static function slice(string $value, string $arg): string
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
