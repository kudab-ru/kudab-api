<?php

declare(strict_types=1);

namespace App\Services\Rinks;

use App\Models\City;
use App\Models\Event;
use App\Models\Rink;
use App\Services\Weather\CityWeather;
use App\Support\CityCase;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Страница катков города (/afisha/{город}/katki) и блок «массовое катание» на странице площадки. */
final class RinkPage
{
    private const TZ = 'Europe/Moscow';

    /** Вкладки «сегодня · завтра · выходные» и строка каждого катка на неделю берут дни отсюда. */
    private const DAYS = 9;

    /** Сеансы катков, которые продают билеты через кассы, приходят обычными событиями площадки. */
    public const SESSION_TITLE = '/массов\w*\s+катани/iu';

    private const SHOW_TITLE = '/ледов\w*\s+шоу|шоу\s+на\s+льду/iu';

    private const HOCKEY_TITLE = '/хокке|\bбуран\b/iu';

    private const SHOWS = 6;

    /** Хоккея не больше двух ближайших матчей, иначе он вытеснит шоу. */
    private const HOCKEY = 2;

    /** Чем каток помогает детям и новичкам: это попадает в ответ «Где покататься с ребёнком?». */
    private const KIDS_HELP = '/упор|подпор|помощник|инструктор|детск|дети до/iu';

    public function __construct(private readonly CityWeather $weather) {}

    /** @return array<string, mixed> */
    public function build(City $city, CarbonImmutable $now): array
    {
        $now = $now->setTimezone(self::TZ);
        $today = $now->startOfDay();
        $rinks = Rink::query()->with('venue:id,slug')->where('city_id', $city->id)->where('hidden', false)
            ->orderBy('sort')->orderBy('id')->get();

        $weather = $this->weather->forDays($city, Carbon::parse($today), Carbon::parse($today->addDays(self::DAYS - 1)->endOfDay()), Carbon::parse($now));
        $tMax = [];
        foreach ((array) ($weather['days'] ?? []) as $d) {
            $tMax[(string) $d['date']] = (int) $d['t_max'];
        }
        $dated = $this->datedFromEvents($rinks, $today);

        $cards = $rinks->map(fn (Rink $r) => $this->card($r, $today, $tMax, $dated[$r->id] ?? []))->values()->all();

        return [
            'today' => $today->toDateString(),
            'now' => $now->format('H:i'),
            'weather' => $weather,
            'rinks' => $cards,
            'hero' => $this->hero($rinks, $cards),
            'faq' => $this->faq($city, $rinks, $cards, $now),
            'shows' => $this->shows($rinks, $now),
        ];
    }

    /**
     * Блок катка на странице площадки: строка для титула, дни недели с сеансами, цены одной строкой.
     *
     * @return array<string, mixed>|null
     */
    public function forVenue(int $venueId, CarbonImmutable $now): ?array
    {
        $rink = Rink::query()->where('venue_id', $venueId)->where('hidden', false)->first();
        if ($rink === null) {
            return null;
        }
        $today = $now->setTimezone(self::TZ)->startOfDay();
        $card = $this->card($rink, $today, [], $this->datedFromEvents(collect([$rink]), $today)[$rink->id] ?? []);

        return $card + ['now' => $now->setTimezone(self::TZ)->format('H:i'), 'today' => $today->toDateString(), 'title' => $this->titleName($rink)];
    }

    /** Как назвать каток в заголовке страницы для поиска: «Каток «Платинум Арена»», «Каток на Адмиралтейской площади». */
    private function titleName(Rink $r): string
    {
        $name = (string) $r->name;

        return match (true) {
            str_starts_with($name, 'Каток') => $name,
            str_starts_with((string) $r->name_in, 'на ') => 'Каток '.$r->name_in,
            str_contains($name, '«') => 'Каток '.$name,
            default => 'Каток «'.$name.'»',
        };
    }

    /**
     * @param  array<string, int>  $tMax
     * @param  array<string, list<array{t: string, url: ?string}>>  $dated
     * @return array<string, mixed>
     */
    private function card(Rink $r, CarbonImmutable $today, array $tMax, array $dated): array
    {
        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $d = $today->addDays($i);
            $days[] = ['date' => $d->toDateString()] + RinkDays::day($r, $d, $tMax[$d->toDateString()] ?? null, $dated);
        }

        return [
            'id' => (int) $r->id,
            'slug' => (string) $r->slug,
            'name' => (string) $r->name,
            'name_in' => $r->name_in,
            'address' => $r->address,
            'area' => $r->area,
            'lat' => $r->lat,
            'lon' => $r->lon,
            'indoor' => (bool) $r->indoor,
            'ice' => $r->ice,
            'entry' => (string) $r->entry,
            'status' => (string) $r->status,
            'status_note' => $r->status_note,
            'status_short' => $r->status_short,
            'status_source' => $r->status_source,
            'opens_on' => $r->opens_on?->toDateString(),
            'week' => array_values(array_map(fn ($row) => ['label' => (string) ($row['label'] ?? ''), 'times' => array_values((array) ($row['times'] ?? []))], (array) $r->schedule)),
            'week_note' => $r->week_note,
            'prices' => (object) ($r->prices ?? []),
            'features' => array_values((array) $r->features),
            'kids' => (bool) $r->kids,
            'links' => (object) ($r->links ?? []),
            'venue_url' => $r->venue !== null ? '/venues/'.$r->venue->id.'-'.$r->venue->slug : null,
            'checked_at' => $r->checked_at?->setTimezone(self::TZ)->toDateString(),
            'days' => $days,
            'next' => RinkDays::next($r, $today, $dated),
        ];
    }

    /**
     * Сеансы по датам из событий площадки: «Массовое катание» с Qtickets и других касс, со ссылкой на покупку.
     *
     * @param  Collection<int, Rink>  $rinks
     * @return array<int, array<string, list<array{t: string, url: ?string}>>>
     */
    private function datedFromEvents(Collection $rinks, CarbonImmutable $today): array
    {
        $byVenue = $rinks->filter(fn (Rink $r) => $r->venue_id !== null)->keyBy('venue_id');
        if ($byVenue->isEmpty()) {
            return [];
        }

        $events = Event::query()
            ->whereIn('venue_id', $byVenue->keys()->all())
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where('time_precision', 'datetime')
            ->where('start_time', '>=', $today->utc())
            ->where('start_time', '<', $today->addDays(22)->utc())
            ->orderBy('start_time')
            ->get(['id', 'venue_id', 'title', 'start_time', 'end_time', 'external_url', 'price_url']);

        $out = [];
        foreach ($events as $e) {
            if (preg_match(self::SESSION_TITLE, (string) $e->title) !== 1) {
                continue;
            }
            $rink = $byVenue->get($e->venue_id);
            $start = Carbon::parse($e->start_time)->setTimezone(self::TZ);
            $end = $e->end_time !== null ? Carbon::parse($e->end_time)->setTimezone(self::TZ) : $start->copy()->addHour();
            $t = $start->format('H:i').'–'.$end->format('H:i');
            $date = $start->toDateString();
            if (in_array($t, array_column($out[$rink->id][$date] ?? [], 't'), true)) {
                continue;
            }
            $out[$rink->id][$date][] = ['t' => $t, 'url' => $e->price_url ?: $e->external_url];
        }

        return $out;
    }

    /**
     * Число в шапке, описание и ярлыки. Работающим сегодня считается каток, где катание точно есть:
     * с предупреждением об оттепели он в число не входит.
     *
     * @param  Collection<int, Rink>  $rinks
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    private function hero(Collection $rinks, array $cards): array
    {
        $open = count(array_filter($cards, fn ($c) => $c['days'][0]['open'] && $c['days'][0]['caution'] === null));
        $thaw = array_filter($cards, fn ($c) => $c['days'][0]['caution'] !== null) !== [];
        $working = $rinks->where('status', 'open');
        $entries = $working->map(fn (Rink $r) => (int) ($r->prices['entry'] ?? 0))->filter()->all();
        $rents = $working->map(fn (Rink $r) => (int) ($r->prices['rental'] ?? 0))->filter()->all();

        $outdoorOpen = $rinks->where('indoor', false)->where('status', 'open');
        $outdoorSoon = $rinks->where('indoor', false)->where('status', 'soon')->sortBy(fn (Rink $r) => $r->opens_on?->toDateString() ?? '9');
        $indoorSoon = $rinks->where('indoor', true)->where('status', 'soon');

        $lead = match (true) {
            $thaw => 'Оттепель: крытые и катки с искусственным льдом работают, на остальных лёд может подтаять.',
            $outdoorOpen->isEmpty() && $outdoorSoon->isNotEmpty() => $this->offSeasonLead($indoorSoon, $outdoorSoon),
            $outdoorOpen->isNotEmpty() => 'Работают крытые и уличные катки: где и когда массовое катание, сколько стоит вход и прокат.',
            default => 'Уличные катки закрылись до зимы, крытые работают круглый год.',
        };
        // ярлык «скоро» — у бесплатного городского катка, его ждут больше других
        $headline = $outdoorSoon->first(fn (Rink $r) => $r->entry === 'free' && $r->status_short !== null) ?? $outdoorSoon->first(fn (Rink $r) => $r->status_short !== null);

        return [
            'open' => $open,
            'lead' => $lead,
            'hot' => $thaw ? 'в оттепель уточняйте' : ($headline !== null ? $headline->name.' '.$headline->status_short : null),
            'entry_from' => $entries !== [] ? min($entries) : null,
            'rental_from' => $rents !== [] ? min($rents) : null,
            'free' => $working->contains(fn (Rink $r) => $r->entry === 'free'),
            'indoor' => $rinks->where('indoor', true)->count(),
            'outdoor' => $rinks->where('indoor', false)->count(),
        ];
    }

    /**
     * @param  Collection<int, Rink>  $indoorSoon
     * @param  Collection<int, Rink>  $outdoorSoon
     */
    private function offSeasonLead(Collection $indoorSoon, Collection $outdoorSoon): string
    {
        $head = 'Крытые катки работают';
        $first = $indoorSoon->first();
        if ($first !== null && $first->status_short !== null) {
            $head .= ', '.$this->quoted($first).' откроет сезон '.$first->status_short;
        }

        return $head.'. '.$this->outdoorWait($outdoorSoon).'.';
    }

    /**
     * «Уличные заливают с морозами, первым обещают каток в «Дельфине» в ноябре»: первым называется каток
     * с известной датой открытия.
     *
     * @param  Collection<int, Rink>  $outdoorSoon
     */
    private function outdoorWait(Collection $outdoorSoon): string
    {
        $first = $outdoorSoon->filter(fn (Rink $r) => $r->opens_on !== null && $r->status_short !== null && $r->status_short !== 'с морозами')
            ->sortBy(fn (Rink $r) => $r->opens_on?->toDateString())->first();

        return 'Уличные заливают с морозами'.($first !== null ? ', первым обещают '.$this->quoted($first).' '.$first->status_short : '');
    }

    /**
     * Ответы одной фразой на то, что спрашивают у поиска; названия катков ведут на их площадки.
     *
     * @param  Collection<int, Rink>  $rinks
     * @param  list<array<string, mixed>>  $cards
     * @return list<array{q: string, a: string, links: list<array{text: string, url: string}>}>
     */
    private function faq(City $city, Collection $rinks, array $cards, CarbonImmutable $now): array
    {
        $in = CityCase::prepositional($city->slug);
        $where = $in !== null ? ' в '.$in : '';
        $bySlug = collect($cards)->keyBy('slug');
        $link = fn (Rink $r) => ['text' => $this->quoted($r), 'url' => $bySlug[$r->slug]['venue_url'] ?? '#k-'.$r->slug];
        $out = [];

        $open = $rinks->where('status', 'open');
        $indoorOpen = $open->where('indoor', true);
        $outdoorOpen = $open->where('indoor', false);
        if ($open->isNotEmpty()) {
            $a = [];
            if ($indoorOpen->isNotEmpty()) {
                $a[] = 'Крытые: '.$this->list($indoorOpen).'.';
            }
            if ($outdoorOpen->isNotEmpty()) {
                $a[] = 'Уличные: '.$this->list($outdoorOpen).'.';
            }
            foreach ($rinks->where('status', 'soon')->where('indoor', true) as $r) {
                $a[] = $this->capital($this->quoted($r)).' '.($r->status_note ?? 'скоро откроется').'.';
            }
            if ($outdoorOpen->isEmpty() && $rinks->where('indoor', false)->isNotEmpty()) {
                $a[] = $this->outdoorWait($rinks->where('indoor', false)->where('status', 'soon')).'.';
            }
            $out[] = ['q' => 'Какие катки'.$where.' работают сейчас?', 'a' => implode(' ', $a), 'links' => $open->map($link)->values()->all()];
        }

        // о каникулах спрашивают с декабря, до их конца
        if ($now->month === 12 || ($now->month === 1 && $now->day <= 10)) {
            $a = $open->isNotEmpty() ? ['В каникулы работают '.$this->list($open).'.'] : [];
            // «с морозами» — догадка по прошлой зиме, а прошедшая дата значит, что каток не сменил статус: обещанием это не назовёшь
            $before = $now->month === 12 ? $rinks->where('status', 'soon')->filter(fn (Rink $r) => $r->opens_on !== null
                && $r->opens_on->year === $now->year && $r->opens_on->toDateString() >= $now->toDateString()
                && $r->name_in !== null && $r->status_short !== null && $r->status_short !== 'с морозами')->sortBy(fn (Rink $r) => $r->opens_on->toDateString()) : collect();
            if ($before->isNotEmpty()) {
                $a[] = 'До Нового года обещают открыть '.($before->count() > 1 ? 'катки ' : 'каток ')
                    .$this->joinAnd($before->map(fn (Rink $r) => $r->name_in.' ('.$r->status_short.')')->values()->all()).'.';
            }
            $a[] = 'В праздники у катков свои часы. Сеансы на каждый день есть в расписании на этой странице, а перед поездкой загляните в группу катка.';
            $out[] = ['q' => 'Какие катки работают в новогодние каникулы?', 'a' => implode(' ', $a), 'links' => $open->map($link)->values()->all()];
        }

        // вечерние сеансы сегодня: от пяти вечера и позже, ещё не начавшиеся
        $evening = [];
        $at = (int) $now->format('Hi');
        foreach ($rinks as $r) {
            $day = $bySlug[$r->slug]['days'][0];
            if (! $day['open'] || $day['caution'] !== null) {
                continue;
            }
            $times = array_values(array_filter(array_map(fn ($s) => explode('–', $s['t'])[0], $day['slots']),
                fn ($t) => (int) str_replace(':', '', $t) >= max(1700, $at)));
            if ($times !== []) {
                $evening[] = [$r, $times];
            }
        }
        if ($evening !== []) {
            $a = implode(' ', array_map(fn ($x) => $this->capital($this->quoted($x[0])).': '.$this->joinAnd($x[1]).'.', $evening));
            $out[] = ['q' => 'Где покататься сегодня вечером?', 'a' => $a, 'links' => array_map(fn ($x) => $link($x[0]), $evening)];
        }

        $headline = $rinks->first(fn (Rink $r) => $r->entry === 'free' && ! $r->indoor && $r->status === 'soon' && $r->name_in !== null);
        if ($headline !== null && $headline->status_note !== null) {
            $out[] = ['q' => 'Когда откроется каток '.$headline->name_in.'?', 'a' => $this->capital($headline->status_note).'.', 'links' => []];
        }

        $free = $rinks->where('entry', 'free');
        if ($free->isNotEmpty()) {
            $parts = $free->map(fn (Rink $r) => ($r->name_in ?? 'на катке '.$this->quoted($r))
                .($r->status === 'soon' && $r->status_short !== null ? ' ('.$r->status_short.')' : ''))->values()->all();
            $a = $this->capital($this->joinAnd($parts)).'. Дворовые катки заливают только в сильный мороз, общего списка город не публикует.';
            $out[] = ['q' => 'Где кататься бесплатно?', 'a' => $a, 'links' => []];
        }

        $kidsNow = $rinks->filter(fn (Rink $r) => $r->kids && in_array($r->status, ['open', 'call'], true));
        $kidsLater = $rinks->filter(fn (Rink $r) => $r->kids && $r->status === 'soon')->sortBy(fn (Rink $r) => $r->opens_on?->toDateString() ?? '9');
        if ($kidsNow->isNotEmpty()) {
            $help = function (Rink $r): string {
                $f = collect((array) $r->features)->first(fn ($x) => preg_match(self::KIDS_HELP, (string) $x) === 1);

                return $this->quoted($r).($f !== null ? ' ('.$f.')' : '');
            };
            $a = 'С детьми удобно: '.$this->joinAnd($kidsNow->map($help)->values()->all()).'.';
            if ($kidsLater->isNotEmpty()) {
                $a .= ' Позже откроются '.$this->joinAnd($kidsLater->map(fn (Rink $r) => $this->quoted($r)
                    .($r->status_short !== null ? ' ('.$r->status_short.')' : ''))->values()->all()).'.';
            }
            $out[] = ['q' => 'Где покататься с ребёнком?', 'a' => $a, 'links' => $kidsNow->merge($kidsLater)->map($link)->values()->all()];
        }

        // ответ постоянный, без сегодняшней погоды: его читают поисковики, а совет по погоде стоит в шапке страницы
        $out[] = ['q' => 'Что надеть на каток?', 'a' => 'В крытых катках прохладно, у самого льда тем более: хватит тёплой кофты, '
            .'а перчатки или варежки нужны всегда. На уличный каток одевайтесь по погоде слоями и возьмите запасные перчатки. '
            .'Шерстяные носки берите выше края ботинка, длинный шарф лучше оставить дома: он развевается и мешает. Детям нужен шлем.', 'links' => []];

        // у входа «200–300 ₽» в `entry` нижняя цена, верхняя только в строке разброса
        $range = function (Collection $list, string $key): ?string {
            $low = $list->map(fn (Rink $r) => (int) ($r->prices[$key] ?? 0))->filter()->values();
            if ($low->isEmpty()) {
                return null;
            }
            $high = $list->map(fn (Rink $r) => preg_match('/–\s*(\d+)/u', (string) ($r->prices[$key.'_range'] ?? ''), $m) === 1 ? (int) $m[1] : (int) ($r->prices[$key] ?? 0))->max();

            return $low->min() === $high ? $this->rub((int) $high) : $low->min().'–'.$this->rub((int) $high);
        };
        $inside = $range($rinks->where('indoor', true), 'entry');
        $outside = $range($rinks->where('indoor', false), 'entry');
        $rent = $range($rinks, 'rental');
        if ($inside !== null || $outside !== null) {
            $a = [];
            if ($inside !== null) {
                $a[] = 'Вход на крытый каток стоит '.$inside;
            }
            if ($outside !== null) {
                $a[] = ($a === [] ? 'Вход на уличный стоит ' : 'на уличный ').$outside;
            }
            $text = implode(', ', $a).'.';
            if ($rinks->contains(fn (Rink $r) => $r->entry === 'free')) {
                $text .= ' Есть бесплатные.';
            }
            if ($rent !== null) {
                $text .= ' Прокат коньков '.$rent.'.';
            }
            $out[] = ['q' => 'Сколько стоит каток'.$where.'?', 'a' => $text, 'links' => []];
        }

        if (array_filter($cards, fn ($c) => $c['days'][0]['caution'] !== null) !== []) {
            $warm = $rinks->filter(fn (Rink $r) => ! $r->indoor && $r->ice === 'artificial' && $r->status === 'open');
            $a = 'Крытые работают как обычно'.($warm->isNotEmpty() ? ', уличные с искусственным льдом тоже: '.$this->list($warm) : '').'. '
                .'На катках с натуральным льдом лёд подтаивает, их иногда закрывают; перед поездкой загляните в группу катка.';
            $out[] = ['q' => 'Работают ли катки в оттепель?', 'a' => $a, 'links' => $warm->map($link)->values()->all()];
        }

        return $out;
    }

    /**
     * Ледовые шоу и хоккей на площадках катков: по одному показу каждого, ближайшие первыми.
     *
     * @param  Collection<int, Rink>  $rinks
     * @return list<array<string, mixed>>
     */
    private function shows(Collection $rinks, CarbonImmutable $now): array
    {
        $venues = $rinks->pluck('venue_id')->filter()->unique()->values()->all();
        if ($venues === []) {
            return [];
        }

        $events = Event::query()->with('venue:id,name')
            ->whereIn('venue_id', $venues)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where('start_time', '>=', $now->utc())
            ->where('start_time', '<', $now->addDays(150)->utc())
            ->orderBy('start_time')
            ->limit(200)
            ->get();

        $out = [];
        $hockey = 0;
        foreach ($events as $e) {
            $title = trim((string) $e->title);
            $key = mb_strtolower($title);
            $kind = match (true) {
                preg_match(self::SHOW_TITLE, $title) === 1 => 'show',
                preg_match(self::HOCKEY_TITLE, $title) === 1 => 'hockey',
                default => null,
            };
            if ($kind === null || isset($out[$key]) || ($kind === 'hockey' && $hockey >= self::HOCKEY)) {
                continue;
            }
            $hockey += $kind === 'hockey' ? 1 : 0;
            $start = Carbon::parse($e->start_time)->setTimezone(self::TZ);
            $out[$key] = [
                'id' => (int) $e->id,
                'kind' => $kind,
                'url' => '/events/'.$e->id,
                'title' => (string) preg_replace('/"([^"]{1,80})"/u', '«$1»', $title),
                'poster' => $e->poster(),
                'venue' => (string) ($e->venue?->name ?? ''),
                'date' => $start->toDateString(),
                'time' => ($e->time_precision ?? 'datetime') === 'date' ? null : $start->format('H:i'),
                'price_min' => $e->price_min !== null ? (int) $e->price_min : null,
                'price_max' => $e->price_max !== null ? (int) $e->price_max : null,
            ];
            if (count($out) >= self::SHOWS) {
                break;
            }
        }

        return array_values($out);
    }

    /** «Платинум Арена» в кавычках, «каток в «Твоём доме»» как есть, со строчной в середине фразы. */
    private function quoted(Rink $r): string
    {
        $name = (string) $r->name;
        if (str_contains($name, '«')) {
            return mb_strtolower(mb_substr($name, 0, 1)).mb_substr($name, 1);
        }

        return '«'.$name.'»';
    }

    /** @param Collection<int, Rink> $list */
    private function list(Collection $list): string
    {
        return $this->joinAnd($list->map(fn (Rink $r) => $this->quoted($r))->values()->all());
    }

    /** @param list<string> $items */
    private function joinAnd(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);

        return implode(', ', $items).' и '.$last;
    }

    private function capital(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    private function rub(int $n): string
    {
        return number_format($n, 0, ',', ' ')."\u{00A0}₽";
    }
}
