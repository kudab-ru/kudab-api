<?php

namespace App\Services\Recommendations;

/**
 * Подбор для блоков «что дальше» на странице события: «В этот день», «Ещё на
 * этой площадке» и «Похожее». Работает на готовых признаках событий, которые
 * собирает `EventRepository::nextPool`, и в базу не ходит.
 */
final class NextEventsPicker
{
    public const LIMIT = 12;

    // «Похожее» ищем в этом окне вокруг дня события.
    public const DAYS_BEFORE = 7;

    public const DAYS_AFTER = 14;

    private const PER_VENUE = 2;

    private const PER_TOPIC_SAME_DAY = 4;

    // Рядом с детским — до 6+, если само событие не старше; рядом с семейным — без 18+.
    private const KIDS_AGE_LIMIT = 12;

    private const FAMILY_AGE_LIMIT = 18;

    // выбор в колесе «Чего хочется?» весит как общий жанр: заметно, но похожесть не перебивает
    private const PREFER_BONUS = 1.0;

    // Слова для причины «тоже …». Темы без слова причину не дают.
    private const TOPIC_WORDS = [
        'music' => 'музыка',
        'theatre' => 'театр',
        'exhibitions' => 'выставка',
        'education' => 'познавательное',
        'literature' => 'литература',
        'parties' => 'вечеринка',
        'excursions' => 'экскурсия',
        'cinema' => 'кино',
        'standup' => 'стендап',
        'festival' => 'фестиваль',
        'quiz-games' => 'игры',
        'city' => 'городское событие',
        'sport' => 'спорт',
        'circus-show' => 'цирк и шоу',
    ];

    private const GENRE_WORDS = [
        'jazz' => 'джаз',
        'classical' => 'классика',
        'pop' => 'эстрада',
        'rock' => 'рок',
        'hip-hop' => 'рэп',
        'electronic' => 'электроника',
        'opera-ballet' => 'опера и балет',
        'workshops' => 'мастер-класс',
        'science' => 'наука',
        'yoga-wellness' => 'йога',
    ];

    /**
     * Чисто детское (квесты тоже помечены детскими) показываем только рядом
     * с детским или семейным событием.
     *
     * @param  list<string>  $topics
     */
    public static function allowsKids(?string $audience, array $topics): bool
    {
        return in_array($audience, ['kids', 'family'], true) || in_array('kids', $topics, true);
    }

    /**
     * @param  array<string, mixed>  $base  признаки события, см. `EventRepository::nextPool`
     * @param  list<array<string, mixed>>  $items  признаки кандидатов
     * @param  'venue'|'community'|null  $companionsScope
     * @param  list<string>  $prefer  темы глаголов, выбранных в колесе на афише
     * @return array{day: ?string, same_day: list<array{id: int, reason: ?string}>, companions: list<array{id: int, reason: ?string}>, similar: list<array{id: int, reason: ?string}>}
     */
    public function pick(array $base, array $items, string $today, ?string $companionsScope, array $prefer = []): array
    {
        $hasDay = $base['day'] !== null && $base['day'] >= $today;
        $refDay = $hasDay ? $base['day'] : $today;

        $scored = [];
        foreach ($items as $e) {
            if ($this->fits($base, $e)) {
                $scored[] = $e + $this->score($base, $e, $refDay, $prefer);
            }
        }

        $byScore = $scored;
        usort($byScore, fn (array $a, array $b) => [$b['score'], $a['start'] ?? PHP_INT_MAX, $a['id']]
            <=> [$a['score'], $b['start'] ?? PHP_INT_MAX, $b['id']]);

        // Само событие, записанное другим источником (то же название или та же площадка и
        // минута), тоже не советуем.
        $taken = array_fill_keys($base['keys'], true);

        $sameDay = $hasDay
            ? $this->take(array_filter($byScore, fn (array $e) => $e['day'] === $refDay), $taken, self::PER_VENUE, self::PER_TOPIC_SAME_DAY)
            : [];

        $companions = [];
        if ($companionsScope !== null) {
            $field = $companionsScope === 'venue' ? 'venue' : 'community';
            $near = array_filter($scored, fn (array $e) => $e[$field] !== null && $e[$field] === $base[$field]);
            usort($near, fn (array $a, array $b) => [$a['day'] ?? '9999-12-31', $a['start'] ?? PHP_INT_MAX, $a['id']]
                <=> [$b['day'] ?? '9999-12-31', $b['start'] ?? PHP_INT_MAX, $b['id']]);
            $companions = $this->take($near, $taken, null, null, false);
        }

        $from = max($today, $this->shiftDay($refDay, -self::DAYS_BEFORE));
        $to = $this->shiftDay($refDay, self::DAYS_AFTER);
        $similar = $this->take(
            array_filter($byScore, fn (array $e) => $e['shares'] && $e['day'] !== null && $e['day'] >= $from && $e['day'] <= $to),
            $taken,
            self::PER_VENUE,
            null,
        );

        return [
            'day' => $hasDay ? $refDay : null,
            'same_day' => $sameDay,
            'companions' => $companions,
            'similar' => $similar,
        ];
    }

    private function fits(array $base, array $e): bool
    {
        $kidsBase = $base['audience'] === 'kids' || in_array('kids', $base['topics'], true);
        $familyBase = $base['audience'] === 'family';

        if ($e['audience'] === 'kids' && ! ($kidsBase || $familyBase)) {
            return false;
        }
        if (($kidsBase || $familyBase) && $e['audience'] === 'adults') {
            return false;
        }
        if ($e['age'] === null) {
            return true;
        }
        if ($kidsBase) {
            return $e['age'] < max(self::KIDS_AGE_LIMIT, ($base['age'] ?? 0) + 1);
        }

        return ! $familyBase || $e['age'] < self::FAMILY_AGE_LIMIT;
    }

    /**
     * Жанры события внутри темы. Жанр в признаках записан как «тема/жанр».
     *
     * @return list<string>
     */
    private function genresOf(array $e, string $topic): array
    {
        return array_values(array_filter($e['genres'], fn (string $g) => str_starts_with($g, $topic.'/')));
    }

    private function forKids(array $e): bool
    {
        return in_array('kids', $e['topics'], true) || in_array($e['audience'], ['kids', 'family'], true);
    }

    /**
     * Больше всего весят общие темы и жанр, затем близость дня к событию, а в
     * тот же день — близость часа: вечернему событию вечернее.
     *
     * @return array{score: float, reason: ?string, shares: bool}
     */
    private function score(array $base, array $e, string $refDay, array $prefer): array
    {
        $shared = array_values(array_intersect($base['topics'], $e['topics']));
        $union = count(array_unique(array_merge($base['topics'], $e['topics'])));
        $genres = array_values(array_intersect($base['genres'], $e['genres']));
        $bothKids = $this->forKids($base) && $this->forKids($e);
        $bothFree = $base['free'] && $e['free'];

        // Общая тема с разными жанрами внутри неё («наука» и «мастер-класс» в образовании) — сходство слабое.
        $clashes = 0;
        foreach ($shared as $t) {
            $mine = $this->genresOf($base, $t);
            $theirs = $this->genresOf($e, $t);
            if ($mine !== [] && $theirs !== [] && array_intersect($mine, $theirs) === []) {
                $clashes++;
            }
        }

        $sameDayHours = $e['day'] === $refDay && $base['minute'] !== null && $e['minute'] !== null
            ? 0.6 / (1.0 + abs($base['minute'] - $e['minute']) / 120.0)
            : 0.0;

        $score = ($union > 0 ? 2.0 * (count($shared) - 0.5 * $clashes) / $union : 0.0)
            + ($genres !== [] ? 1.0 : 0.0)
            + ($bothKids ? 1.0 : 0.0)
            + ($bothFree ? 0.3 : 0.0)
            + ($e['photo'] ? 0.3 : 0.0)
            - ($e['gray'] ? 0.3 : 0.0)
            + ($e['day'] !== null ? 1.0 / (1.0 + abs($this->daysBetween($refDay, $e['day'])) / 3.0) : 0.0)
            + $sameDayHours
            + (array_intersect($e['topics'], $prefer) !== [] ? self::PREFER_BONUS : 0.0);

        return [
            'score' => $score,
            'reason' => $this->reason($e, $shared, $genres, $bothKids, $bothFree),
            'shares' => $shared !== [],
        ];
    }

    private function reason(array $e, array $sharedTopics, array $sharedGenres, bool $bothKids, bool $bothFree): ?string
    {
        if ($bothKids) {
            return $e['audience'] === 'family' && ! in_array('kids', $e['topics'], true)
                ? 'можно с детьми'
                : 'тоже для детей';
        }
        foreach ($sharedGenres as $g) {
            $slug = str_contains($g, '/') ? substr($g, strpos($g, '/') + 1) : $g;
            if (isset(self::GENRE_WORDS[$slug])) {
                return 'тоже '.self::GENRE_WORDS[$slug];
            }
        }
        foreach ($sharedTopics as $t) {
            if (isset(self::TOPIC_WORDS[$t])) {
                return 'тоже '.self::TOPIC_WORDS[$t];
            }
        }

        return $bothFree ? 'тоже бесплатно' : null;
    }

    /**
     * Одна «вещь» попадает в блоки один раз: ключи серии, анонса и названия
     * помечаются занятыми для всех следующих блоков.
     *
     * @param  iterable<array<string, mixed>>  $list
     * @param  array<string, true>  $taken
     * @return list<array{id: int, reason: ?string}>
     */
    private function take(iterable $list, array &$taken, ?int $perVenue, ?int $perTopic, bool $withReason = true): array
    {
        $out = [];
        $venues = [];
        $topics = [];

        foreach ($list as $e) {
            if (count($out) >= self::LIMIT) {
                break;
            }
            foreach ($e['keys'] as $k) {
                if (isset($taken[$k])) {
                    continue 2;
                }
            }

            $venue = $e['venue'];
            $topic = $e['topics'][0] ?? null;
            if ($perVenue !== null && $venue !== null && ($venues[$venue] ?? 0) >= $perVenue) {
                continue;
            }
            if ($perTopic !== null && $topic !== null && ($topics[$topic] ?? 0) >= $perTopic) {
                continue;
            }

            foreach ($e['keys'] as $k) {
                $taken[$k] = true;
            }
            if ($venue !== null) {
                $venues[$venue] = ($venues[$venue] ?? 0) + 1;
            }
            if ($topic !== null) {
                $topics[$topic] = ($topics[$topic] ?? 0) + 1;
            }

            $out[] = ['id' => $e['id'], 'reason' => $withReason ? $e['reason'] : null];
        }

        return $out;
    }

    private function daysBetween(string $from, string $to): int
    {
        return intdiv(strtotime($to.' UTC') - strtotime($from.' UTC'), 86400);
    }

    private function shiftDay(string $day, int $days): string
    {
        return gmdate('Y-m-d', strtotime($day.' UTC') + $days * 86400);
    }
}
