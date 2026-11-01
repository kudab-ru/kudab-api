<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Название события для строки подборки. Источники пишут в название дату, площадку и город
 * («04.11 - Суперкозлы - Котельная», «1 ноября / Halloween gig / Diesel bar»), а в подборке
 * это уже сказано строкой фактов. Пустая строка — от названия остался один повод гида.
 */
final class DigestTitle
{
    private const MONTHS = 'январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр';

    /** Короче — площадку внутри названия не ищем: «Арт» нашёлся бы в «Артель» и «Арт-рок». */
    private const MIN_VENUE = 4;

    /**
     * @param  string|null  $city  «Воронеж»
     * @param  string|null  $cityIn  «Воронеже»
     * @param  list<string>  $occasionWords  слова повода гида: «ночь искусств», «halloween»
     */
    public static function clean(string $title, ?string $venue = null, ?string $city = null, ?string $cityIn = null, array $occasionWords = []): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', str_replace('\\"', '"', $title)));
        $t = (string) preg_replace('/\s+в\s+рамках\s.*$/iu', '', $t);
        // хвост после тире со строчной буквы — пояснение, а не название
        $t = (string) preg_replace('/\s+[—–]\s+\p{Ll}.*$/u', '', $t);
        $t = (string) preg_replace('/\s+\d{1,2}\.\d{1,2}(\.\d{2,4})?$/u', '', $t);

        $parts = preg_split('/\s+(?:\/\/|\/|\||[—–-])\s+/u', $t) ?: [$t];
        $kept = array_values(array_filter($parts, fn (string $p) => ! self::isNoise($p, $venue, $city, $occasionWords)));
        if ($kept !== []) {
            $t = implode(' — ', $kept);
        }

        if ($cityIn !== null && $cityIn !== '') {
            $t = (string) preg_replace('/\s+в\s+'.preg_quote($cityIn, '/').'$/iu', '', $t);
        }

        if ($occasionWords !== []) {
            $rest = $t;
            foreach ($occasionWords as $w) {
                $rest = (string) preg_replace('/'.preg_quote($w, '/').'/iu', '', $rest);
            }
            $rest = trim((string) preg_replace('/^[\s\d–—:,.«»"-]+/u', '', $rest));
            if ($rest === '' || preg_match('/^в\s/u', $rest) === 1 || $kept === []) {
                return '';
            }
        }

        return (string) preg_replace('/^[\s\/|–—-]+|[\s\/|–—-]+$/u', '', $t);
    }

    /** @param list<string> $occasionWords */
    private static function isNoise(string $part, ?string $venue, ?string $city, array $occasionWords): bool
    {
        // trim() режет байты, а «» и тире многобайтные: откусил бы конец последней буквы
        $p = mb_strtolower((string) preg_replace('/^[\s"«»]+|[\s"«»]+$/u', '', $part));
        if ($p === '') {
            return true;
        }
        if (preg_match('/^\d{1,2}([.\/]\d{1,2}([.\/]\d{2,4})?)?$/u', $p) === 1
            || preg_match('/^\d{1,2}\s+(?:'.self::MONTHS.')\p{L}*$/u', $p) === 1) {
            return true;
        }
        if ($city !== null && $p === mb_strtolower($city)) {
            return true;
        }
        $v = mb_strtolower(trim(str_replace(['«', '»', '"'], '', (string) $venue)));
        $bare = str_replace(['«', '»', '"'], '', $p);
        if (mb_strlen($v) >= self::MIN_VENUE && mb_strlen($bare) >= self::MIN_VENUE
            && (str_contains($v, $bare) || str_contains($bare, $v))) {
            return true;
        }
        foreach ($occasionWords as $w) {
            if ($p === mb_strtolower($w)) {
                return true;
            }
        }

        return false;
    }
}
