<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Разбор видимых на сайте записей «Что сейчас в афише». Событие считается один раз, сколько бы
 * у него ни было показов: ключ тот же, что у схлопывания серий в ленте (федерация, группа, запись).
 */
final class AfishaBreakdown
{
    private const CALENDAR_DAYS = 35;

    private const VENUES = 10;

    /** Больше дней подряд, чем пропусков, и хотя бы пять дней — это «идёт каждый день», а не серия дат. */
    private const DAILY_MIN_DAYS = 5;

    private const DAILY_MIN_SHARE = 0.7;

    /** @param  list<int>  $ids  записи, которые сейчас видит посетитель */
    public function __construct(private readonly array $ids, private readonly CarbonImmutable $now) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $today = $this->now->toDateString();
        $empty = [
            'events' => 0,
            'sessions' => 0,
            'shapes' => ['once' => 0, 'dates' => 0, 'daily' => 0],
            'added_24h' => 0,
            'soon' => ['today' => 0, 'tomorrow' => 0, 'weekend' => $this->weekend() + ['count' => 0], 'week' => 0],
            'days' => $this->calendar([]),
            'later' => 0,
            'themes' => [],
            'untagged' => 0,
            'venues' => ['top' => [], 'with_events' => 0, 'without_venue' => 0],
            'sources' => ['rows' => [], 'multi' => 0, 'communities' => 0],
        ];
        if ($this->ids === []) {
            return $empty;
        }

        $totals = $this->one("
            SELECT COUNT(*) AS events,
                   COUNT(*) FILTER (WHERE shape = 'once') AS once,
                   COUNT(*) FILTER (WHERE shape = 'dates') AS dates,
                   COUNT(*) FILTER (WHERE shape = 'daily') AS daily,
                   COUNT(*) FILTER (WHERE first_seen >= :fresh) AS fresh,
                   COUNT(*) FILTER (WHERE d1 > :last) AS later
            FROM shapes
        ", ['fresh' => $this->now->subDay()->utc()->toDateTimeString(), 'last' => $this->lastDay()]);
        $sessions = (int) ($this->one('SELECT COUNT(*) AS n FROM s')->n ?? 0);

        $weekend = $this->weekend();
        $soon = $this->one('
            SELECT COUNT(DISTINCT k) FILTER (WHERE start_date = :today) AS today,
                   COUNT(DISTINCT k) FILTER (WHERE start_date = :tomorrow) AS tomorrow,
                   COUNT(DISTINCT k) FILTER (WHERE start_date BETWEEN :ws AND :we) AS weekend,
                   COUNT(DISTINCT k) FILTER (WHERE start_date BETWEEN :today2 AND :week) AS week
            FROM s
        ', [
            'today' => $today,
            'tomorrow' => $this->now->addDay()->toDateString(),
            'ws' => $weekend['from'],
            'we' => $weekend['to'],
            'today2' => $today,
            'week' => $this->now->addDays(6)->toDateString(),
        ]);

        return [
            'events' => (int) $totals->events,
            'sessions' => $sessions,
            'shapes' => ['once' => (int) $totals->once, 'dates' => (int) $totals->dates, 'daily' => (int) $totals->daily],
            'added_24h' => (int) $totals->fresh,
            'soon' => [
                'today' => (int) $soon->today,
                'tomorrow' => (int) $soon->tomorrow,
                'weekend' => $weekend + ['count' => (int) $soon->weekend],
                'week' => (int) $soon->week,
            ],
            'days' => $this->calendar($this->rows('
                SELECT s.start_date::text AS day, sh.shape, COUNT(DISTINCT s.k) AS n
                FROM s JOIN shapes sh ON sh.k = s.k
                WHERE s.start_date BETWEEN :from AND :to
                GROUP BY 1, 2
            ', ['from' => $today, 'to' => $this->lastDay()])),
            'later' => (int) $totals->later,
        ] + $this->themes((int) $totals->events) + [
            'venues' => $this->venues(),
            'sources' => $this->sources(),
        ];
    }

    /** @return array{themes: list<array{slug: string, name: string, count: int}>, untagged: int} */
    private function themes(int $events): array
    {
        // жанр («рок») считается в свою тему («музыка»): часть событий висит только на жанре
        $rows = $this->rows('
            SELECT t.slug, t.name, COUNT(DISTINCT s.k) AS n
            FROM s
            JOIN event_interest ei ON ei.event_id = s.id
            JOIN interests i ON i.id = ei.interest_id
            JOIN interests t ON t.id = COALESCE(i.parent_id, i.id)
            GROUP BY t.slug, t.name
            ORDER BY n DESC, t.name
        ');
        $tagged = (int) ($this->one('SELECT COUNT(DISTINCT s.k) AS n FROM s JOIN event_interest ei ON ei.event_id = s.id')->n ?? 0);

        return [
            'themes' => array_map(fn ($r) => ['slug' => (string) $r->slug, 'name' => (string) $r->name, 'count' => (int) $r->n], $rows),
            'untagged' => max(0, $events - $tagged),
        ];
    }

    /** @return array{top: list<array{id: int, slug: string, name: string, count: int}>, with_events: int, without_venue: int} */
    private function venues(): array
    {
        $top = $this->rows('
            SELECT v.id, v.slug, v.name, COUNT(DISTINCT s.k) AS n
            FROM s JOIN venues v ON v.id = s.venue_id
            GROUP BY v.id, v.slug, v.name
            ORDER BY n DESC, v.name
            LIMIT '.self::VENUES);
        $counts = $this->one('
            SELECT COUNT(DISTINCT venue_id) AS with_events,
                   COUNT(DISTINCT k) FILTER (WHERE venue_id IS NULL) AS without_venue
            FROM s
        ');

        return [
            'top' => array_map(fn ($r) => ['id' => (int) $r->id, 'slug' => (string) $r->slug, 'name' => (string) $r->name, 'count' => (int) $r->n], $top),
            'with_events' => (int) $counts->with_events,
            'without_venue' => (int) $counts->without_venue,
        ];
    }

    /**
     * Группы ВК и каналы Telegram идут одной строкой на сеть, сайты и афиши — каждый своей.
     *
     * @return array{rows: list<array{label: string, events: int, communities: int}>, multi: int, communities: int}
     */
    private function sources(): array
    {
        $rows = $this->rows("
            SELECT CASE es.source WHEN 'vk' THEN 'ВКонтакте' WHEN 'telegram' THEN 'Telegram' ELSE c.name END AS label,
                   COUNT(DISTINCT s.k) AS events,
                   COUNT(DISTINCT l.community_id) AS communities
            FROM s
            JOIN event_sources es ON es.event_id = s.id
            JOIN community_social_links l ON l.id = es.social_link_id
            JOIN communities c ON c.id = l.community_id
            GROUP BY 1
            ORDER BY events DESC, label
        ");
        $multi = $this->one('
            SELECT COUNT(*) AS n FROM (
                SELECT s.k
                FROM s
                JOIN event_sources es ON es.event_id = s.id
                JOIN community_social_links l ON l.id = es.social_link_id
                GROUP BY s.k
                HAVING COUNT(DISTINCT l.community_id) > 1
            ) x
        ');
        $communities = $this->one('
            SELECT COUNT(DISTINCT l.community_id) AS n
            FROM s
            JOIN event_sources es ON es.event_id = s.id
            JOIN community_social_links l ON l.id = es.social_link_id
        ');

        return [
            'rows' => array_map(fn ($r) => ['label' => (string) $r->label, 'events' => (int) $r->events, 'communities' => (int) $r->communities], $rows),
            'multi' => (int) ($multi->n ?? 0),
            'communities' => (int) ($communities->n ?? 0),
        ];
    }

    /**
     * Дни календаря подряд от сегодня, у каждого число событий трёх видов.
     *
     * @param  list<object{day: string, shape: string, n: int}>  $rows
     * @return list<array{date: string, once: int, dates: int, daily: int}>
     */
    private function calendar(array $rows): array
    {
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r->day][$r->shape] = (int) $r->n;
        }

        $days = [];
        for ($i = 0; $i < self::CALENDAR_DAYS; $i++) {
            $date = $this->now->addDays($i)->toDateString();
            $days[] = [
                'date' => $date,
                'once' => $byDay[$date]['once'] ?? 0,
                'dates' => $byDay[$date]['dates'] ?? 0,
                'daily' => $byDay[$date]['daily'] ?? 0,
            ];
        }

        return $days;
    }

    /**
     * Ближайшие суббота и воскресенье; в воскресенье — только оно.
     *
     * @return array{from: string, to: string}
     */
    private function weekend(): array
    {
        $dow = $this->now->dayOfWeekIso;
        if ($dow === 7) {
            return ['from' => $this->now->toDateString(), 'to' => $this->now->toDateString()];
        }
        $saturday = $this->now->addDays(6 - $dow);

        return ['from' => $saturday->toDateString(), 'to' => $saturday->addDay()->toDateString()];
    }

    private function lastDay(): string
    {
        return $this->now->addDays(self::CALENDAR_DAYS - 1)->toDateString();
    }

    /** @return list<object> */
    private function rows(string $select, array $bindings = []): array
    {
        return DB::select($this->with().$select, ['ids' => $this->idsLiteral()] + $bindings);
    }

    private function one(string $select, array $bindings = []): object
    {
        return $this->rows($select, $bindings)[0] ?? (object) [];
    }

    /** Общее начало всех запросов: записи с ключом события и вид каждого события. */
    private function with(): string
    {
        return '
            WITH s AS (
                SELECT e.id, e.start_date, e.venue_id, e.created_at,
                       COALESCE(eg.federation_id, e.event_group_id, -e.id) AS k
                FROM events e
                LEFT JOIN event_groups eg ON eg.id = e.event_group_id AND eg.deleted_at IS NULL
                WHERE e.id = ANY(:ids::bigint[])
            ),
            shapes AS (
                SELECT k, MIN(start_date) AS d1, MIN(created_at) AS first_seen,
                       CASE
                           WHEN COUNT(DISTINCT start_date) = 1 THEN \'once\'
                           WHEN COUNT(DISTINCT start_date) >= '.self::DAILY_MIN_DAYS.'
                            AND COUNT(DISTINCT start_date) >= '.self::DAILY_MIN_SHARE.' * ((MAX(start_date) - MIN(start_date)) + 1)
                           THEN \'daily\'
                           ELSE \'dates\'
                       END AS shape
                FROM s
                GROUP BY k
            )
        ';
    }

    private function idsLiteral(): string
    {
        return '{'.implode(',', array_map('intval', $this->ids)).'}';
    }
}
