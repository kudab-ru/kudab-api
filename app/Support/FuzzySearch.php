<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Нестрогий поиск по триграммам — тот же рецепт, что у поиска событий на сайте.
 *
 * ОТКУДА. Правила придуманы не здесь: они живут в EventRepository (приватные
 * pickFuzzyToken/fuzzyThreshold/trgmEnabled и константа FUZZY_MIN_LEN) и там
 * откатаны на живой выдаче. Этот класс — их единственная публичная копия, чтобы
 * третий потребитель не изобретал пороги заново. EventRepository намеренно НЕ
 * переписан на него: 2994 строки, поиск в трёх местах, и правка ради красоты
 * стоила бы дороже дубля.
 *
 * ПОЧЕМУ word_similarity, А НЕ similarity. similarity сравнивает строку целиком,
 * поэтому «никитин» против «никитинский театр» даёт мало: знаменатель растёт
 * вместе с длиной названия. word_similarity ищет лучшее совпадение по СЛОВУ и на
 * той же паре даёт 0.875. Замер 20.09.2026 на живой базе: «дивногорь» → 0.9,
 * опечатка «дивнагорье» → 0.57, «театр» против «Дивногорья» → 0.
 *
 * ПОЧЕМУ ТОЛЬКО ОДНО СЛОВО ЗАПРОСА. Берём самое длинное: короткие слова («в»,
 * «на», «дом») дают высокое сходство с чем угодно и превращают поиск в шум.
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
