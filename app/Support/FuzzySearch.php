<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Нестрогий поиск по триграммам для админки площадок. Пороги мягче, чем у поиска событий
 * на сайте (см. `EventRepository::FUZZY_FORM_THRESHOLD`): ловят опечатку вроде «дивнагорье»,
 * но заодно находят и слова, у которых совпадает только начало.
 */
final class FuzzySearch
{
    /** Короче этого триграммы бессмысленны: совпадёт половина каталога. */
    private const MIN_TOKEN_LEN = 4;

    private static ?bool $enabled = null;

    /**
     * Доступны ли триграммы. Расширение может быть не поставлено на чужой базе
     * (тесты, свежий стенд) — тогда поиск молча остаётся строгим, а не падает.
     */
    public static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        try {
            DB::selectOne("select word_similarity('a','a') as s");
            self::$enabled = true;
        } catch (\Throwable) {
            self::$enabled = false;
        }

        return self::$enabled;
    }

    /** Самое длинное слово запроса — по нему и меряем сходство. */
    public static function token(string $query): string
    {
        $parts = preg_split('~\s+~u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $token = '';
        foreach ($parts as $p) {
            if (mb_strlen($p) > mb_strlen($token)) {
                $token = $p;
            }
        }

        return $token !== '' ? $token : trim($query);
    }

    /**
     * Порог сходства. Чем длиннее слово, тем ниже планка: в длинном слове
     * одна опечатка весит меньше, а ложных совпадений всё равно меньше.
     */
    public static function threshold(string $token): float
    {
        $n = mb_strlen($token);
        if ($n <= 4) {
            return 0.20;
        }
        if ($n <= 6) {
            return 0.18;
        }

        return 0.14;
    }

    /** Стоит ли вообще включать нестрогий путь для этого запроса. */
    public static function applicable(string $token): bool
    {
        return self::enabled() && mb_strlen($token) >= self::MIN_TOKEN_LEN;
    }

    /** Строка для LIKE: экранируем служебные символы, иначе «%» ищет всё. */
    public static function like(string $query): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($query))).'%';
    }
}
