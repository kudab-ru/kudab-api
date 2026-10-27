<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Models\City;
use App\Support\CityCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Страница подборки на несколько дней подряд («На выходных», «Сегодня», «Бесплатно»): программа по дням,
 * отдельно то, что идёт без часа все эти дни, главное и праздник на эти дни. Строки собираются так же, как
 * у праздника (OccasionPage::assemble); событие на странице стоит один раз, см. withoutPicks.
 */
final class SliceBuilder
{
    private const TZ = 'Europe/Moscow';

    /** Сайт сворачивает день по частям, предел только от разросшегося ответа. */
    private const DAY_LIMIT = 150;

    private const MONTHS = [1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'];

    private const MONTHS_SHORT = [1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'мая', 6 => 'июн',
        7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек'];

    public function __construct(
        private readonly OccasionPage $page,
        private readonly OccasionPromo $promo,
    ) {}

    /**
     * Дни с `from` по `to`. `anchor` — день, по которому ищется пост рубрики канала; null — у страницы нет
     * «Главного», а с ним и карточки праздника. С `dropPast` прошедшего на странице нет: «Сегодня» вечером
     * не показывает утро.
     *
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    public function build(array $theme, City $city, Carbon $from, Carbon $to, ?Carbon $anchor, Carbon $now, bool $dropPast): array
    {
        // события праздника не занимают места в «Главном»: туда встаёт карточка праздника, она к ним и ведёт
        $holiday = $anchor === null ? null : $this->holiday($city, $from, $to, $now);
        $part = $this->page->assemble($theme + ['skip' => $holiday['ids'] ?? []], $city, $now, [$from, $to], $anchor, self::DAY_LIMIT);
        $live = fn (array $r) => ! $dropPast || ! $r['is_past'];
        $rows = array_values(array_filter(array_map(fn (array $r) => ['times' => $this->clock($r['times'])] + $r, $part['rows']), $live));
        $picks = array_values(array_filter(array_map(fn (array $p) => ['times' => $this->clock($p['times'])] + $p, $part['picks']), $live));
        $until = $this->lastDates($rows, $to);
        $score = array_column($rows, 'score', 'id');

        // что идёт без часа все эти дни (выставки), одной строкой отдельно: в программе по дням оно стояло бы в каждом
        $span = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $bySeries = [];
        foreach ($rows as $r) {
            $bySeries[$r['series']][] = $r;
        }
        $both = [];
        $bothIds = [];
        foreach ($bySeries as $list) {
            // у одного дня без часа весь день идёт только выставка или то, что тянется дальше; концерт без часа просто не назвал время
            $allDay = $span > 1 ? count($list) >= $span : $list[0]['kind']['key'] === 'exhibition' || isset($until[$list[0]['id']]);
            if (! $allDay || array_filter($list, fn (array $r) => $r['times'] !== []) !== []) {
                continue;
            }
            $both[] = $this->row($list[0], $until, $score);
            array_push($bothIds, ...array_column($list, 'id'));
        }
        usort($both, fn (array $a, array $b) => $b['rank'] <=> $a['rank']);

        $days = [];
        foreach ($part['days'] as $d) {
            $events = array_filter($d['events'], fn (array $e) => $live($e) && ! in_array($e['id'], $bothIds, true));
            $days[] = [
                'date' => $d['date'], 'weekday' => $d['weekday'], 'label' => $d['label'],
                'events' => array_values(array_map(fn (array $e) => $this->row($e, $until, $score), $events)),
            ];
        }

        // событие — серия, а не строка: спектакль и в субботу, и в воскресенье — одно событие
        $series = [];
        foreach ($rows as $r) {
            $series[$r['series']] ??= $r;
        }
        $kinds = $part['kinds'];

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'label' => $this->label($from, $to, false),
            'short' => $this->label($from, $to, true),
            'days_left' => max(0, (int) $now->copy()->startOfDay()->diffInDays($from, false)),
            'total' => count($series),
            'free' => count(array_filter($series, fn (array $r) => $r['free'])),
            'kids' => count(array_filter($series, fn (array $r) => $r['kids'])),
            'lead' => $this->lead(count($series), $kinds),
            'kinds' => $kinds,
            'picks' => $picks,
            'days' => $days,
            'both' => $both,
            'holiday' => $holiday,
            // «Коротко» — только у подборки с вопросом в теме, см. OccasionPage::faq
            'faq' => isset($theme['question'])
                ? $this->page->faq($theme, CityCase::prepositional($city->slug), null, $picks, $rows)
                : [],
        ];
    }

    /** @see OccasionPage::withoutPicks */
    public function withoutPicks(array $w): array
    {
        return $this->page->withoutPicks($w);
    }

    /** @see OccasionPage::sameTitle */
    public function sameTitle(string $title): string
    {
        return $this->page->sameTitle($title);
    }

    /**
     * Строка программы для сайта: `rank` — оценка интереса (по ней сайт выбирает, что видно до «ещё»),
     * `until` — последний день серии, если она идёт и после этих дней.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $until
     * @param  array<int, int|float>  $score
     * @return array<string, mixed>
     */
    private function row(array $row, array $until, array $score): array
    {
        $out = array_diff_key($row, ['score' => true, 'series' => true]);
        $out['times'] = $this->clock((array) $row['times']);
        $out['until'] = $until[$row['id']] ?? $row['until'] ?? null;
        $out['rank'] = (int) round((float) ($score[$row['id']] ?? 0));

        return $out;
    }

    /**
     * Полночь — запись без часа, а не сеанс: сайт и карта тоже считают её неизвестным временем.
     *
     * @param  list<string>  $times
     * @return list<string>
     */
    private function clock(array $times): array
    {
        return array_values(array_filter($times, fn ($t) => $t !== '00:00'));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string> событие → последний день его серии, если он позже этих дней
     */
    private function lastDates(array $rows, Carbon $to): array
    {
        $groups = [];
        foreach ($rows as $r) {
            if (str_starts_with((string) $r['series'], 'g')) {
                $groups[(int) substr((string) $r['series'], 1)][] = (int) $r['id'];
            }
        }
        if ($groups === []) {
            return [];
        }

        $last = DB::table('events')
            ->whereIn('event_group_id', array_keys($groups))
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->groupBy('event_group_id')
            ->selectRaw('event_group_id, max(start_date) as last')
            ->pluck('last', 'event_group_id');

        $out = [];
        foreach ($groups as $group => $ids) {
            $day = substr((string) ($last[$group] ?? ''), 0, 10);
            if ($day !== '' && $day > $to->toDateString()) {
                foreach ($ids as $id) {
                    $out[$id] = $day;
                }
            }
        }

        return $out;
    }

    /**
     * Праздник, который выпадает на эти дни и у которого в эти дни есть события.
     *
     * @return array{page: string, title: string, count: int, ids: list<int>}|null
     */
    private function holiday(City $city, Carbon $from, Carbon $to, Carbon $now): ?array
    {
        foreach ($this->page->calendar($now) as $c) {
            if ($c['badge'] > $to->toDateString() || $c['until'] < $from->toDateString()) {
                continue;
            }
            $theme = OccasionCalendar::byPage($c['page']);
            if ($theme === null) {
                continue;
            }
            $ids = [];
            foreach ((array) $this->promo->pageData((array) $theme, $city)['days'] as $d) {
                if ($d['date'] >= $from->toDateString() && $d['date'] <= $to->toDateString()) {
                    array_push($ids, ...array_map('intval', array_column((array) $d['events'], 'id')));
                }
            }
            if ($ids !== []) {
                return ['page' => (string) $c['page'], 'title' => (string) $c['title'], 'count' => count($ids), 'ids' => $ids];
            }
        }

        return null;
    }

    /** @param  list<array{key: string, label: string, count: int}>  $kinds */
    private function lead(int $total, array $kinds): string
    {
        if ($total === 0) {
            return 'Афиша на эти дни ещё собирается: площадки объявляют события ближе к дате.';
        }
        $names = array_map(fn (array $k) => mb_strtolower($k['label']), array_filter($kinds, fn (array $k) => $k['key'] !== 'for-kids'));

        return $total.' '.$this->plural($total, 'событие', 'события', 'событий')
            .($names !== [] ? ': '.$this->listing(array_slice(array_values($names), 0, 3)) : '').'.';
    }

    /** «a», «a и b», «a, b и c» */
    private function listing(array $parts): string
    {
        return count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)).' и '.end($parts) : (string) ($parts[0] ?? '');
    }

    private function label(Carbon $from, Carbon $to, bool $short): string
    {
        $m = $short ? self::MONTHS_SHORT : self::MONTHS;
        if ($from->isSameDay($to)) {
            return $from->day.' '.$m[$from->month];
        }

        return $from->month === $to->month
            ? $from->day.'–'.$to->day.' '.$m[$from->month]
            : $from->day.' '.$m[$from->month].' – '.$to->day.' '.$m[$to->month];
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $a = $n % 100;
        $b = $n % 10;
        if ($a >= 11 && $a <= 14) {
            return $many;
        }

        return $b === 1 ? $one : ($b >= 2 && $b <= 4 ? $few : $many);
    }
}
