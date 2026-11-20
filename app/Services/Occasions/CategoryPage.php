<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Models\City;
use App\Support\WeekendWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

final class CategoryPage
{
    private const TZ = 'Europe/Moscow';

    private const WEEKS = 5;

    private const CACHE_SECONDS = 300;

    private const STALE_SECONDS = 3600;

    private const REL = ['эта неделя', 'следующая неделя', 'через две недели', 'через три недели', 'через четыре недели'];

    private const WEEKDAYS = [1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье'];

    private const MONTHS = [1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'];

    private const MONTHS_NOM = [1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'];

    private const MONTHS_SHORT = [1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'мая', 6 => 'июн',
        7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек'];

    private const CLASSICAL_WORDS = ['классик', 'симфон', 'оркестр', 'фортепиано', 'скрипк', 'виолончел', 'струнн', 'опер', 'хор', 'кантат', 'сонат'];

    public function __construct(
        private readonly SliceBuilder $slices,
        private readonly OccasionPage $page,
    ) {}

    /** @return array<string, mixed> */
    public function show(City $city, Carbon $now, ?string $want, ?string $month): array
    {
        $now = $now->copy()->setTimezone(self::TZ);
        $today = $now->copy()->startOfDay();
        $summary = Cache::flexible(
            'category-page:summary:v1:'.$city->slug.':'.$today->toDateString(),
            [self::CACHE_SECONDS, self::STALE_SECONDS],
            fn () => $this->page->agenda($this->theme(), $city, [$now, $today->copy()->addYear()->endOfDay()]),
        );

        $weeks = [];
        for ($w = 0; $w < self::WEEKS; $w++) {
            $from = $today->copy()->addWeeks($w);
            $to = $from->copy()->addDays(6);
            $days = [];
            for ($d = 0; $d < 7; $d++) {
                $day = $from->copy()->addDays($d);
                $iso = $day->toDateString();
                $days[] = [
                    'date' => $iso,
                    'weekday' => self::WEEKDAYS[$day->isoWeekday()],
                    'label' => $day->day.' '.self::MONTHS[$day->month],
                    'count' => (int) ($summary['days'][$iso] ?? 0),
                ];
            }
            $weeks[] = [
                'w' => $from->toDateString(),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $this->rangeLabel($from, $to, false),
                'short' => $this->rangeLabel($from, $to, true),
                'rel' => self::REL[$w],
                'total' => array_sum(array_column($days, 'count')),
                'days' => $days,
            ];
        }

        $laterFrom = $today->copy()->addWeeks(self::WEEKS);
        $monthCounts = [];
        foreach ($summary['days'] as $date => $count) {
            if ($date >= $laterFrom->toDateString()) {
                $key = substr((string) $date, 0, 7);
                $monthCounts[$key] = ($monthCounts[$key] ?? 0) + $count;
            }
        }
        ksort($monthCounts);
        $months = [];
        foreach ($monthCounts as $key => $count) {
            $start = Carbon::parse($key.'-01', self::TZ);
            $from = $start->lt($laterFrom) ? $laterFrom->copy() : $start->copy();
            $to = $start->copy()->endOfMonth();
            $months[] = [
                'key' => $key,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $from->day > 1
                    ? $this->rangeLabel($from, $to, false)
                    : self::MONTHS_NOM[$from->month].($from->year !== $today->year ? ' '.$from->year : ''),
                'total' => $count,
            ];
        }

        $selected = $weeks[0];
        foreach ($weeks as $week) {
            if ($week['w'] === $want) {
                $selected = $week;
                break;
            }
        }
        $selectedType = 'week';
        if ($month !== null) {
            foreach ($months as $item) {
                if ($item['key'] === $month) {
                    $selected = $item;
                    $selectedType = 'month';
                    break;
                }
            }
        }

        $current = $this->window(
            $city,
            Carbon::parse($selected['from'], self::TZ),
            Carbon::parse($selected['to'], self::TZ)->endOfDay(),
        );
        $current = $this->slices->withoutPicks($current);
        $current['total'] = $selected['total'];
        $current['month'] = $selectedType === 'month' ? $selected['key'] : null;
        $current['free'] = count(array_filter($this->programRows($current), fn (array $e) => $e['free']));
        $current['cheap'] = count(array_filter($this->programRows($current),
            fn (array $e) => ! $e['free'] && (int) ($e['price_min'] ?? 0) >= 1 && (int) ($e['price_min'] ?? 0) <= 1000));

        return [
            'page' => 'koncerty',
            'kind' => 'concert',
            'weeks' => $weeks,
            'months' => $months,
            'current' => $current,
            'selected' => ['type' => $selectedType, 'key' => $selectedType === 'month' ? $selected['key'] : $selected['w']],
            'venues' => $summary['venues'],
            'short' => $this->short($city, $now),
        ];
    }

    /** @return array<string, mixed> */
    private function window(City $city, Carbon $from, Carbon $to): array
    {
        return Cache::flexible(
            'category-page:window:v2:'.$city->slug.':'.$from->toDateString().':'.$to->toDateString(),
            [self::CACHE_SECONDS, self::STALE_SECONDS],
            fn () => ['w' => $from->toDateString()] + $this->slices->build(
                $this->theme(), $city, $from, $to, $from, Carbon::now(self::TZ), true,
            ),
        );
    }

    /** @return array<string, mixed> */
    private function short(City $city, Carbon $now): array
    {
        return Cache::flexible(
            'category-page:short:v1:'.$city->slug.':'.$now->toDateString(),
            [self::CACHE_SECONDS, self::STALE_SECONDS],
            function () use ($city, $now) {
                $part = $this->page->assemble(
                    $this->theme(), $city, $now, [$now, $now->copy()->addDays(120)->endOfDay()], null, 150,
                );
                $series = [];
                foreach ($part['rows'] as $row) {
                    $series[$row['series']] ??= $row;
                }
                $events = array_values($series);

                $prices = array_values(array_filter(array_map(
                    fn (array $e) => (int) ($e['price_min'] ?? 0), $events,
                ), fn (int $price) => $price > 0));
                sort($prices);
                $n = count($prices);
                $price = $n === 0 ? null : [
                    'q1' => $n < 3 ? $this->median($prices) : $this->median(array_slice($prices, 0, intdiv($n, 2))),
                    'q2' => $this->median($prices),
                    'q3' => $n < 3 ? $this->median($prices) : $this->median(array_slice($prices, intdiv($n + 1, 2))),
                ];

                $free = array_values(array_filter($events, fn (array $e) => $e['free']));
                $classical = array_values(array_filter($events, fn (array $e) => mb_stripos((string) ($e['venue'] ?? ''), 'филармон') !== false
                    || (in_array('classical', $e['interests'], true) && OccasionCalendar::titleNames($e['title'], self::CLASSICAL_WORDS))));

                return [
                    'prices' => $price,
                    'free' => $this->shortCards($free, 5),
                    'weekend' => $this->shortWeekend($events, $now),
                    'classical' => $this->shortCards($classical, 3),
                ];
            },
        );
    }

    /** @return list<array<string, mixed>> */
    private function shortCards(array $rows, int $limit): array
    {
        usort($rows, fn (array $a, array $b) => [$a['date'], $a['times'][0] ?? '99', -$a['score']]
            <=> [$b['date'], $b['times'][0] ?? '99', -$b['score']]);

        return array_map(fn (array $e) => [
            'title' => $e['title'],
            'url' => $e['url'],
            'date' => $e['date'],
            'time' => $e['times'][0] ?? null,
            'venue' => $e['venue'],
        ], array_slice($rows, 0, $limit));
    }

    /** @return list<array<string, mixed>> */
    private function shortWeekend(array $events, Carbon $now): array
    {
        [$from, $to] = WeekendWindow::for($now);
        $byDay = [];
        foreach ($events as $event) {
            if ($event['date'] >= $from->toDateString() && $event['date'] <= $to->toDateString()) {
                $byDay[$event['date']][] = $event;
            }
        }
        ksort($byDay);
        $out = [];
        foreach ($byDay as $date => $rows) {
            usort($rows, fn (array $a, array $b) => $b['score'] <=> $a['score']);
            $day = Carbon::parse($date, self::TZ);
            $out[] = [
                'date' => $date,
                'weekday' => self::WEEKDAYS[$day->isoWeekday()],
                'label' => $day->day.' '.self::MONTHS[$day->month],
                'events' => $this->shortCards(array_slice($rows, 0, 3), 3),
            ];
        }

        return $out;
    }

    /** @param  non-empty-list<int>  $values */
    private function median(array $values): int
    {
        $n = count($values);

        return $n % 2 ? $values[intdiv($n, 2)] : intdiv($values[$n / 2 - 1] + $values[$n / 2], 2);
    }

    /** @return list<array<string, mixed>> */
    private function programRows(array $current): array
    {
        $rows = [];
        foreach ($current['days'] as $day) {
            array_push($rows, ...$day['events']);
        }

        return [...$rows, ...$current['both']];
    }

    private function rangeLabel(Carbon $from, Carbon $to, bool $short): string
    {
        $months = $short ? self::MONTHS_SHORT : self::MONTHS;
        if ($from->isSameDay($to)) {
            return $from->day.' '.$months[$from->month];
        }

        return $from->month === $to->month
            ? $from->day.'–'.$to->day.' '.$months[$from->month]
            : $from->day.' '.$months[$from->month].' – '.$to->day.' '.$months[$to->month];
    }

    /** @return array<string, mixed> */
    private function theme(): array
    {
        return [
            'page' => 'koncerty',
            'slug' => 'koncerty',
            'title' => 'Концерты',
            'kind' => 'concert',
            'site_pick' => true,
            'channel_pick' => false,
            'picks' => 5,
            'pick_kind_limit' => 5,
            'slim_rows' => true,
            'holidays' => [],
            'rinks' => false,
        ];
    }
}
