<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Кандидаты на слияние площадок — то же, что показывает `parser:venues:duplicates`,
 * но для экрана админки.
 *
 * ИСТОЧНИК ПРАВИЛ — команда парсера (VenuesDuplicatesCommand) и его
 * VenueNameNormalizer. Здесь их порт: сервисы разные, общего кода у них нет,
 * а гонять консольную команду из веб-ручки нельзя. Если правила разъедутся,
 * экран и команда начнут показывать разные пары — поэтому нормализация
 * повторена дословно, а тест пришпилен к живым парам каталога.
 *
 * Почему дубли вообще появляются: одна площадка приходит дважды — из HQ
 * сообщества и через cold-resolve по имени. Пара коварная, потому что ЕГРЮЛ
 * отдаёт адрес РЕГИСТРАЦИИ: «Дивногорье» существует и в 78 км от города
 * (сам заповедник), и на Кольцовской в центре (офис), а события делятся
 * между ними пополам.
 *
 * Сливать автоматически нельзя: «какая из двух настоящая» — знание о месте,
 * а не о строке. Поэтому наружу едут обе стороны со всеми признаками, а
 * решение остаётся за человеком.
 */
final class VenueDuplicateFinder
{
    /** Ближе этого точки считаем одним местом даже при разных адресных строках. */
    private const NEAR_POINT_M = 150.0;

    /**
     * Категорийные токены: не считаются различающим сигналом при сравнении имён.
     * Дословно из VenueNameNormalizer::TYPE_STOPWORDS.
     *
     * @var list<string>
     */
    private const TYPE_STOPWORDS = [
        'театр', 'центр', 'парк', 'музей', 'зал', 'дом', 'клуб', 'кафе',
        'бар', 'дворец', 'библиотека', 'галерея', 'кинотеатр', 'филармония',
        'площадь', 'сквер', 'набережная', 'студия', 'лекторий', 'пространство',
        'комплекс', 'имени', 'им',
        'и', 'на', 'в', 'с',
    ];

    /** Дословно VenueNameNormalizer::normalize. */
    public static function normalize(string $name): string
    {
        $t = mb_strtolower(trim($name), 'UTF-8');
        $t = str_replace('ё', 'е', $t);
        $t = str_replace(['«', '»', '“', '”', '„', '‟', '"'], ' ', $t);
        $t = str_replace(['—', '–', '-'], ' ', $t);
        $t = preg_replace('~#[\p{L}\p{N}_]+~u', ' ', $t) ?? $t;
        $t = preg_replace('~@[\p{L}\p{N}_.]+~u', ' ', $t) ?? $t;
        $t = preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $t) ?? $t;
        $t = preg_replace('~\s+~u', ' ', $t) ?? $t;

        return trim($t);
    }

    /**
     * Дословно VenueNameNormalizer::significantTokens.
     *
     * @return list<string>
     */
    public static function significantTokens(string $name): array
    {
        $tokens = preg_split('~\s+~u', self::normalize($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff(array_unique($tokens), self::TYPE_STOPWORDS));
    }

    /**
     * @param  Collection<int, object>  $venues  строки venues + events_count
     * @return list<array<string, mixed>>
     */
    public static function pairs(Collection $venues): array
    {
        $list = $venues->all();
        $out = [];

        // 1) Точное совпадение нормализованного имени в пределах города — уверенный дубль.
        $groups = [];
        foreach ($list as $v) {
            $groups[$v->city_id.'|'.self::normalize((string) $v->name)][] = $v;
        }
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            for ($i = 0; $i < count($group); $i++) {
                for ($j = $i + 1; $j < count($group); $j++) {
                    $out[] = self::pair($group[$i], $group[$j], 'same_name');
                }
            }
        }

        // 2) Одно имя вложено в другое И место сходится — предположение, не приговор.
        // «Библиотека Никитина» и «Дом-музей Никитина» тоже вложены, но это разные
        // места: гейт по расстоянию отсекает их, а решает всё равно человек.
        $seen = [];
        foreach ($out as $p) {
            $seen[self::key($p['a']['id'], $p['b']['id'])] = true;
        }
        foreach ($list as $i => $a) {
            $aTokens = self::significantTokens((string) $a->name);
            if ($aTokens === []) {
                continue;
            }
            foreach (array_slice($list, $i + 1) as $b) {
                if ($a->city_id !== $b->city_id || isset($seen[self::key((int) $a->id, (int) $b->id)])) {
                    continue;
                }
                $bTokens = self::significantTokens((string) $b->name);
                if ($bTokens === [] || $aTokens === $bTokens) {
                    continue;
                }
                $smaller = count($aTokens) <= count($bTokens) ? $aTokens : $bTokens;
                $larger = count($aTokens) <= count($bTokens) ? $bTokens : $aTokens;
                if (count(array_intersect($smaller, $larger)) !== count($smaller)) {
                    continue;
                }

                $d = self::distanceM($a, $b);
                $sameHouse = $a->house_fias_id !== null && $a->house_fias_id === $b->house_fias_id;
                if (! $sameHouse && ($d === null || $d > self::NEAR_POINT_M)) {
                    continue;
                }

                $out[] = self::pair($a, $b, 'nested_name');
            }
        }

        // Сначала уверенные, внутри — где больше событий на кону.
        usort($out, function (array $x, array $y): int {
            if ($x['reason'] !== $y['reason']) {
                return $x['reason'] === 'same_name' ? -1 : 1;
            }

            return $y['events_at_stake'] <=> $x['events_at_stake'];
        });

        return $out;
    }

    private static function key(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    /** @return array<string, mixed> */
    private static function pair(object $a, object $b, string $reason): array
    {
        $d = self::distanceM($a, $b);

        return [
            'reason' => $reason,
            'distance_m' => $d !== null ? (int) round($d) : null,
            'same_house' => $a->house_fias_id !== null && $a->house_fias_id === $b->house_fias_id,
            'events_at_stake' => (int) $a->events_count + (int) $b->events_count,
            // Подсказка, а не указание: сторона с юридическим адресом почти всегда
            // офис из ЕГРЮЛ, а не место проведения. Если такая ровно одна —
            // называем её кандидатом на слияние. Иначе молчим.
            'suggest_merge_id' => self::suggestMergeId($a, $b),
            'a' => self::side($a),
            'b' => self::side($b),
        ];
    }

    private static function suggestMergeId(object $a, object $b): ?int
    {
        $aLegal = self::meta($a)['legal_address'] ?? false;
        $bLegal = self::meta($b)['legal_address'] ?? false;

        if ($aLegal && ! $bLegal) {
            return (int) $a->id;
        }
        if ($bLegal && ! $aLegal) {
            return (int) $b->id;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function side(object $v): array
    {
        $meta = self::meta($v);

        return [
            'id' => (int) $v->id,
            'name' => $v->name,
            'kind' => $v->kind,
            'address' => $v->address,
            'lat' => $v->latitude !== null ? (float) $v->latitude : null,
            'lon' => $v->longitude !== null ? (float) $v->longitude : null,
            'has_fias' => $v->house_fias_id !== null,
            'legal_address' => (bool) ($meta['legal_address'] ?? false),
            'origin' => $meta['origin'] ?? null,
            'events' => (int) $v->events_count,
        ];
    }

    /** @return array<string, mixed> */
    private static function meta(object $v): array
    {
        $raw = $v->source_meta ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : [];
    }

    /** Гаверсинус; null, если у одной из сторон нет точки. */
    private static function distanceM(object $a, object $b): ?float
    {
        if ($a->latitude === null || $a->longitude === null || $b->latitude === null || $b->longitude === null) {
            return null;
        }

        $r = 6371000.0;
        $lat1 = deg2rad((float) $a->latitude);
        $lat2 = deg2rad((float) $b->latitude);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad((float) $b->longitude - (float) $a->longitude);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($h)));
    }
}
