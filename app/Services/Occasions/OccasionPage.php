<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Models\City;
use App\Models\Event;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Telegram\BroadcastDigestComposer;
use App\Services\Telegram\EventCaptionBuilder;
use App\Services\Telegram\Scoring\EventBroadcastScorer;
use App\Support\CalmCaps;
use App\Support\CityCase;
use App\Support\EventStart;
use App\Support\Telegram\KindEmoji;
use App\Support\Telegram\VenueName;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Страница праздника на сайте: главное (тот же состав, что пост в канал), программа по дням, ответы для
 * поиска и следующие поводы. События берутся правилами повода (OccasionCalendar) и видимостью сайта.
 */
final class OccasionPage
{
    private const TZ = 'Europe/Moscow';

    private const PICKS = 5;

    /** Больше строк в дне — уже лента, а не программа: остальное ссылкой на афишу дня. */
    private const DAY_LIMIT = 15;

    private const WEEKDAYS = [1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье'];

    private const MONTHS = [1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'];

    /** Вид по слову в названии сильнее темы: «Готический Halloween» с темой «Городские акции» — маркет. */
    private const KIND_WORDS = [
        'market' => ['маркет', 'ярмарк', 'барахолк'],
        'quiz' => ['квиз', 'quiz'],
        'party' => ['вечеринк', 'party', 'пати', 'дискотек'],
        'workshop' => ['мастер-класс', 'мастер класс'],
        'excursion' => ['экскурси'],
        'theatre' => ['спектакл'],
        'concert' => ['концерт'],
        'exhibition' => ['выставк'],
        'standup' => ['стендап', 'stand-up'],
        'lecture' => ['лекци'],
        'show' => ['шоу'],
    ];

    private const KIND_BY_INTEREST = [
        'parties' => 'party', 'quiz-games' => 'quiz', 'music' => 'concert', 'theatre' => 'theatre', 'kids' => 'kids',
        'exhibitions' => 'exhibition', 'standup' => 'standup', 'education' => 'lecture', 'literature' => 'lecture',
        'excursions' => 'excursion', 'cinema' => 'cinema', 'circus-show' => 'show', 'festival' => 'festival',
        'city' => 'city', 'sport' => 'sport', 'markets' => 'market',
    ];

    /** [одно, много] */
    private const KIND_LABELS = [
        'party' => ['вечеринка', 'вечеринки'], 'quiz' => ['квиз', 'квизы'], 'market' => ['маркет', 'маркеты'],
        'concert' => ['концерт', 'концерты'], 'theatre' => ['спектакль', 'спектакли'], 'exhibition' => ['выставка', 'выставки'],
        'workshop' => ['мастер-класс', 'мастер-классы'], 'excursion' => ['экскурсия', 'экскурсии'],
        'lecture' => ['лекция', 'лекции'], 'cinema' => ['кино', 'кино'], 'show' => ['шоу', 'шоу'],
        'festival' => ['фестиваль', 'фестивали'], 'standup' => ['стендап', 'стендап'], 'sport' => ['спорт', 'спорт'],
        'city' => ['городское событие', 'городские события'], 'kids' => ['детский праздник', 'детские праздники'],
        'other' => ['событие', 'события'],
    ];

    /** @var array<int, array{slug: string, root: string}>|null */
    private ?array $interestRoots = null;

    private ?City $city = null;

    public function __construct(
        private readonly BroadcastDigestComposer $composer,
        private readonly EventBroadcastScorer $scorer,
    ) {}

    /** @param  array<string, mixed>  $theme */
    public function build(array $theme, City $city, Carbon $now): array
    {
        $now = $now->copy()->setTimezone(self::TZ);
        $this->city = $city;
        $cityIn = CityCase::prepositional($city->slug);
        $day = BroadcastDigestComposer::occasionDate($theme, $now);
        $window = OccasionCalendar::window($theme, $now);

        $events = $window === null ? collect() : $this->events($theme, $city, $window);
        // признаки оценки одним запросом на все события, а не по запросу на каждое
        $this->scorer->prepare($events->all());
        $rows = $this->foldSeries($this->rows($events, $now));
        $days = $this->days($rows);
        $picks = $day === null ? [] : $this->picks($theme, $city, $day, $events, $now);

        $headline = trim((string) preg_replace('/\s+(?=[:,.]|$)/u', '',
            str_replace('{city}', $cityIn !== null ? 'в '.$cityIn : '', (string) ($theme['headline'] ?? $theme['title']))));
        $kinds = $this->kinds($rows);
        $total = count($rows);
        $lead = $this->lead($theme, $total, $rows, $kinds);

        return [
            'occasion' => [
                'page' => (string) $theme['page'],
                'title' => (string) $theme['title'],
                'emoji' => (string) ($theme['emoji'] ?? ''),
                'headline' => $headline,
                'date' => $day?->toDateString(),
                'date_label' => $day ? $this->dayMonth($day) : null,
                'weekday' => $day ? self::WEEKDAYS[$day->isoWeekday()] : null,
                'year' => $day?->year,
                'window' => $window ? ['from' => $window[0]->toDateString(), 'to' => $window[1]->toDateString()] : null,
                'window_label' => $window ? $this->windowLabel($theme, $window) : null,
                'kicker' => $day && $window ? $this->kicker($theme, $day, $window) : null,
                'days_left' => $day ? max(0, (int) $now->copy()->startOfDay()->diffInDays($day, false)) : null,
                'total' => $total,
                'lead' => $lead,
            ],
            'picks' => $picks,
            'days' => $days,
            'kinds' => $kinds,
            'faq' => $this->faq($theme, $cityIn, $day, $picks, $rows),
            'next' => $this->next($theme, $now),
            'seo' => $this->seo($headline, $total, $day, $window, $theme, $lead),
        ];
    }

    /**
     * @param  array<string, mixed>  $theme
     * @param  array{0: Carbon, 1: Carbon}  $window
     * @return Collection<int, Event>
     */
    private function events(array $theme, City $city, array $window): Collection
    {
        $q = Event::query()
            ->with(['venue', 'sources:id,event_id,images,published_at', 'interests:id,slug,parent_id'])
            ->where('events.city_id', $city->id)
            ->where('events.status', 'active')
            ->whereNull('events.deleted_at')
            ->webNotBlacklisted()
            ->webFeedContentKind()
            ->where(fn ($q) => EventStart::notBefore($q, $window[0], 'events'))
            ->where(fn ($q) => EventStart::notAfter($q, $window[1], 'events'))
            // часам повода событие без часа не годится, см. BroadcastDigestComposer::poolForTheme
            ->when(isset($theme['hours']), fn ($q) => $q->where('events.time_precision', '<>', 'date'))
            ->orderByRaw(EventStart::sql('events'))
            ->limit(400);

        if (in_array($theme['pick'] ?? '', ['words', 'all'], true)) {
            OccasionCalendar::applyWords($q, $theme, 'events');
        }

        // дальше всё считается от start_time: событию без часа ставим полночь его дня
        return $q->get()->each(function (Event $e) {
            $e->start_time ??= EventStart::of($e);
        });
    }

    /**
     * Строка программы на событие дня; сеансы одного события в один день — одной строкой с несколькими часами.
     *
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $events, Carbon $now): array
    {
        // запись без времени — после записей того же дня со временем: из двух остаётся та, где час есть
        $sorted = $events->sortBy(fn (Event $e) => [
            $this->localDay($e), ($e->time_precision ?? null) === 'date', (string) $e->start_time,
        ])->values();

        $out = [];
        foreach ($sorted as $e) {
            $day = $this->localDay($e);
            $key = $day.'|'.($e->event_group_id ?: mb_strtolower(trim((string) $e->title)));
            $time = ($e->time_precision ?? null) === 'date' ? null : Carbon::parse($e->start_time)->setTimezone(self::TZ)->format('H:i');

            if (isset($out[$key])) {
                if ($time !== null && ! in_array($time, $out[$key]['times'], true)) {
                    $out[$key]['times'][] = $time;
                }

                continue;
            }

            $out[$key] = $this->dto($e, $now) + [
                'times' => $time !== null ? [$time] : [],
                'series' => $e->event_group_id ? 'g'.$e->event_group_id : 't'.mb_strtolower(trim((string) $e->title)),
                'until' => null,
            ];
        }

        return array_values($out);
    }

    /**
     * Событие, которое идёт три дня и больше, — одной строкой в первый предстоящий день с датой
     * последнего показа: иначе ежедневный спектакль занимает каждый день программы.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function foldSeries(array $rows): array
    {
        $bySeries = [];
        foreach ($rows as $i => $row) {
            $bySeries[$row['series']][] = $i;
        }

        $drop = [];
        foreach ($bySeries as $idx) {
            if (count($idx) < 3) {
                continue;
            }
            $keep = collect($idx)->first(fn ($i) => ! $rows[$i]['is_past']) ?? $idx[0];
            $rows[$keep]['until'] = $rows[end($idx)]['date'];
            $drop = array_merge($drop, array_diff($idx, [$keep]));
        }

        return array_values(array_diff_key($rows, array_flip($drop)));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function days(array $rows): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['date']][] = $row;
        }

        $out = [];
        foreach ($byDay as $date => $items) {
            $more = 0;
            if (count($items) > self::DAY_LIMIT) {
                // самое интересное дня, но по порядку времени
                $keep = collect($items)->sortByDesc('score')->take(self::DAY_LIMIT)->pluck('id')->all();
                $more = count($items) - self::DAY_LIMIT;
                $items = array_values(array_filter($items, fn ($r) => in_array($r['id'], $keep, true)));
            }
            $d = Carbon::parse($date, self::TZ);
            $out[] = [
                'date' => $date,
                'weekday' => self::WEEKDAYS[$d->isoWeekday()],
                'label' => $this->dayMonth($d),
                'events' => array_map(fn ($r) => array_diff_key($r, ['score' => true, 'series' => true]), $items),
                'more' => $more,
            ];
        }

        return $out;
    }

    /**
     * Главное: состав поста в канал с фразами модели; поста ещё нет — то, что собрал бы автомат.
     *
     * @param  array<string, mixed>  $theme
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    private function picks(array $theme, City $city, Carbon $day, Collection $events, Carbon $now): array
    {
        $byId = $events->keyBy('id');
        $item = $this->channelItem($theme, $city, $day);
        $meta = (array) ($item?->digest_meta ?? []);

        $ids = $item !== null
            ? DB::table('telegram.chat_broadcast_item_events')->where('item_id', $item->id)->orderBy('position')->pluck('event_id')->map(fn ($v) => (int) $v)->all()
            : $this->autoPick($theme, $city, $day, $events, $now);

        $out = [];
        foreach ($ids as $id) {
            $e = $byId->get($id);
            if ($e === null || count($out) >= self::PICKS) {
                continue;
            }
            $out[] = $this->dto($e, $now) + [
                'times' => ($e->time_precision ?? null) === 'date' ? [] : [Carbon::parse($e->start_time)->setTimezone(self::TZ)->format('H:i')],
                'hook' => trim((string) (((array) ($meta['hooks'] ?? []))[(string) $id] ?? '')) ?: null,
                'emoji' => trim((string) (((array) ($meta['emoji'] ?? []))[(string) $id] ?? '')) ?: KindEmoji::for([
                    'title' => $e->title,
                    'interest_slugs' => $e->interests->pluck('slug')->all(),
                    'content_kind' => $e->content_kind,
                ], suppressWhenTitleSaysIt: false),
            ];
        }

        return array_map(fn ($r) => array_diff_key($r, ['score' => true]), $out);
    }

    /** Подборка этого повода в канале города: черновик или вышедшая, не снятая и не напоминание. */
    private function channelItem(array $theme, City $city, Carbon $day): ?TelegramChatBroadcastItem
    {
        return TelegramChatBroadcastItem::query()
            ->join('telegram.chat_broadcasts as b', 'b.id', '=', 'telegram.chat_broadcast_items.broadcast_id')
            ->join('telegram.chats as c', 'c.id', '=', 'b.chat_id')
            ->where('c.city_id', $city->id)
            ->where('telegram.chat_broadcast_items.kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->whereNotIn('telegram.chat_broadcast_items.status', [TelegramChatBroadcastItem::STATUS_SKIPPED, TelegramChatBroadcastItem::STATUS_REJECTED])
            ->whereRaw("coalesce(telegram.chat_broadcast_items.digest_meta->>'theme', telegram.chat_broadcast_items.digest_meta->>'theme_wanted') = ?", [(string) $theme['slug']])
            ->whereRaw("telegram.chat_broadcast_items.digest_meta->>'reply_to' is null")
            ->whereRaw('coalesce(telegram.chat_broadcast_items.publish_at, telegram.chat_broadcast_items.posted_at, telegram.chat_broadcast_items.created_at) between ? and ?', [
                $day->copy()->subDays(30)->utc(), $day->copy()->addDays((int) ($theme['window_after'] ?? 0) + 1)->utc(),
            ])
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('telegram.chat_broadcast_item_events as l')
                ->whereColumn('l.item_id', 'telegram.chat_broadcast_items.id'))
            ->orderByRaw('telegram.chat_broadcast_items.posted_at desc nulls last')
            ->orderByDesc('telegram.chat_broadcast_items.updated_at')
            ->select('telegram.chat_broadcast_items.*')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $theme
     * @param  Collection<int, Event>  $events
     * @return list<int>
     */
    private function autoPick(array $theme, City $city, Carbon $day, Collection $events, Carbon $now): array
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat.city')
            ->whereHas('chat', fn ($q) => $q->where('city_id', $city->id))
            ->where('enabled', true)
            ->orderBy('id')
            ->first();
        if ($broadcast !== null) {
            $at = $day->copy()->subDays((int) ($theme['lead_days'] ?? 7))->setTime((int) ($theme['hour'] ?? 12), 0)->max($now);
            $picked = $this->composer->compose($broadcast, $at, null, (string) $theme['slug']);
            if ($picked !== null) {
                return array_map('intval', (array) $picked['event_ids']);
            }
        }

        // канала в городе нет: по оценке интереса, праздник в названии выше, одна строка на площадку
        $words = (array) ($theme['words'] ?? []);
        $ranked = collect($this->scorer->rankRows($events->all()))
            ->sortByDesc(fn ($e) => $this->scorer->interest($e) + (OccasionCalendar::titleNames((string) $e->title, $words) ? 20 : 0));
        $ids = [];
        $venues = [];
        foreach ($ranked as $e) {
            $place = $e->venue_id ?: mb_strtolower((string) $e->address);
            if (isset($venues[$place])) {
                continue;
            }
            $venues[$place] = true;
            $ids[] = (int) $e->id;
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function dto(Event $e, Carbon $now): array
    {
        $start = Carbon::parse($e->start_time)->setTimezone(self::TZ);
        $kind = $this->kindOf($e);

        return [
            'id' => (int) $e->id,
            'title' => $this->cleanTitle((string) $e->title),
            'url' => '/events/'.$e->id,
            'date' => $start->toDateString(),
            'start_at' => $start->toIso8601String(),
            'time_precision' => (string) ($e->time_precision ?? 'datetime'),
            'venue' => $this->venueLabel($e),
            'price_status' => (string) ($e->price_status ?? 'unknown'),
            'price_min' => $e->price_min !== null ? (int) $e->price_min : null,
            'price_max' => $e->price_max !== null ? (int) $e->price_max : null,
            'price_text' => $e->price_text,
            'price_url' => $e->price_url,
            'free' => $e->price_status === 'free' || ((int) ($e->price_min ?? -1) === 0 && $e->price_max === null),
            'poster' => $e->poster(),
            'interests' => $e->interests->pluck('slug')->values()->all(),
            'kind' => ['key' => $kind, 'label' => self::KIND_LABELS[$kind][0]],
            'kids' => $kind === 'kids' || ($e->audience ?? null) === 'kids'
                || preg_match('/(?<![\p{L}])(для детей|детск)/iu', (string) $e->title) === 1,
            'is_past' => ($e->time_precision ?? null) === 'date'
                ? $start->toDateString() < $now->toDateString()
                : $start->copy()->addHours(3)->lt($now),
            'score' => $this->scorer->interest($e),
        ];
    }

    private function kindOf(Event $e): string
    {
        $title = mb_strtolower((string) $e->title);
        foreach (self::KIND_WORDS as $kind => $words) {
            if (OccasionCalendar::titleNames($title, $words)) {
                return $kind;
            }
        }

        $main = $e->interests->first();
        if ($main !== null) {
            $root = $this->rootOf((int) $main->id);
            if (isset(self::KIND_BY_INTEREST[$main->slug])) {
                return self::KIND_BY_INTEREST[$main->slug];
            }
            if ($root !== null && isset(self::KIND_BY_INTEREST[$root])) {
                return self::KIND_BY_INTEREST[$root];
            }
        }

        return 'other';
    }

    private function rootOf(int $interestId): ?string
    {
        if ($this->interestRoots === null) {
            $all = DB::table('interests')->get(['id', 'slug', 'parent_id'])->keyBy('id');
            $this->interestRoots = [];
            foreach ($all as $id => $row) {
                $parent = $row->parent_id !== null ? $all->get($row->parent_id) : null;
                $this->interestRoots[(int) $id] = ['slug' => (string) $row->slug, 'root' => (string) ($parent->slug ?? $row->slug)];
            }
        }

        return $this->interestRoots[$interestId]['root'] ?? null;
    }

    /** Имя площадки: у групп ВК «Настольные игры | ПараDice | Воронеж» — та часть, что есть в названии. */
    private function venueLabel(Event $e): ?string
    {
        $raw = trim((string) ($e->venue?->name ?? ''));
        if ($raw === '') {
            $place = EventCaptionBuilder::placeFromAddress((string) ($e->address ?? ''), (string) ($this->city?->name ?? ''));

            return $place !== '' ? $place : null;
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/\s+[|\/]\s+/u', $raw) ?: [$raw])));
        foreach ($parts as $part) {
            if (mb_strlen($part) >= 3 && mb_stripos((string) $e->title, $part) !== false) {
                return $part;
            }
        }

        return VenueName::label($raw);
    }

    /** Без капса, ёлочки вместо прямых кавычек и без «в Воронеже» в конце: город уже в заголовке страницы. */
    private function cleanTitle(string $title): string
    {
        $title = (string) preg_replace('/"([^"]{1,80})"/u', '«$1»', CalmCaps::title(trim($title)));
        $in = CityCase::prepositional($this->city?->slug);

        return $in !== null ? (string) preg_replace('/\s+в\s+'.preg_quote($in, '/').'$/u', '', $title) : $title;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, count: int}>
     */
    private function kinds(array $rows): array
    {
        $counts = [];
        $kids = 0;
        foreach ($rows as $row) {
            $k = $row['kind']['key'];
            $counts[$k] = ($counts[$k] ?? 0) + 1;
            $kids += $row['kids'] ? 1 : 0;
        }
        // «событие» ничего не говорит, кнопкой его не ставим
        unset($counts['other']);
        arsort($counts);

        $out = [];
        foreach (array_slice($counts, 0, 5, true) as $k => $n) {
            $out[] = ['key' => $k, 'label' => $this->kindLabel($k, $n, true), 'count' => $n];
        }
        // «Детям» — всё детское, какого бы вида ни было: детская вечеринка на Хэллоуин — вечеринка
        if ($kids > 0 && $kids < count($rows) && $kids !== ($counts['kids'] ?? 0)) {
            $out[] = ['key' => 'for-kids', 'label' => 'Детям', 'count' => $kids];
        }

        return $out;
    }

    private function kindLabel(string $kind, int $n, bool $capital = false): string
    {
        $label = self::KIND_LABELS[$kind][$n === 1 ? 0 : 1] ?? 'события';

        return $capital ? mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1) : $label;
    }

    /**
     * @param  array<string, mixed>  $theme
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{key: string, label: string, count: int}>  $kinds
     */
    private function lead(array $theme, int $total, array $rows, array $kinds): string
    {
        if ($total === 0) {
            return 'События к празднику появятся ближе к дате: афиша собирает их, как только о них объявляют.';
        }

        $dates = array_column($rows, 'date');
        $from = Carbon::parse(min($dates), self::TZ);
        $to = Carbon::parse(max($dates), self::TZ);
        $span = $this->span($from, $to);
        $names = array_map(fn ($k) => $this->kindLabel($k['key'], $k['count']),
            array_values(array_filter($kinds, fn ($k) => $k['key'] !== 'for-kids')));
        $names = array_slice($names, 0, 3);
        $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' и '.end($names) : ($names[0] ?? '');

        return $total.' '.$this->plural($total, 'событие', 'события', 'событий').' '.$span.($list !== '' ? ': '.$list : '').'.';
    }

    /**
     * Ответы для поиска собираются из того, что на странице: названия, площадки и часы, без общих слов.
     *
     * @param  array<string, mixed>  $theme
     * @param  list<array<string, mixed>>  $picks
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{q: string, a: string}>
     */
    private function faq(array $theme, ?string $cityIn, ?Carbon $day, array $picks, array $rows): array
    {
        $out = [];
        $city = $cityIn !== null ? 'в '.$cityIn : '';
        $q = fn (string $key) => trim((string) preg_replace('/\s+(?=\?)/u', '', str_replace('{city}', $city, (string) ($theme[$key] ?? ''))));

        if (($theme['question'] ?? '') !== '' && $picks !== []) {
            $byDay = [];
            foreach ($picks as $p) {
                $byDay[$p['date']][] = $p;
            }
            ksort($byDay);
            $parts = [];
            foreach (array_slice($byDay, 0, 3, true) as $date => $items) {
                $d = Carbon::parse($date, self::TZ);
                $parts[] = $this->capital(self::WEEKDAYS[$d->isoWeekday()]).', '.$this->dayMonth($d).': '
                    .implode('; ', array_map(fn ($p) => $this->line($p), $items));
            }
            $out[] = ['q' => $q('question'), 'a' => implode('. ', $parts).'.'];
        }

        $kids = array_values(array_filter($rows, fn ($r) => $r['kids']));
        if ($kids !== [] && count($kids) < count($rows)) {
            $out[] = ['q' => 'Что есть для детей?', 'a' => implode('; ', array_map(fn ($r) => $this->line($r, true), array_slice($kids, 0, 3))).'.'];
        }

        $free = array_values(array_filter($rows, fn ($r) => in_array($r['price_status'], ['free', 'donation'], true)));
        $cheap = array_values(array_filter($rows, fn ($r) => $r['price_min'] !== null && $r['price_min'] > 0 && $r['price_min'] <= 500));
        if ($free !== []) {
            $out[] = ['q' => 'Есть ли бесплатные события?', 'a' => 'Да: '.implode('; ', array_map(fn ($r) => $this->line($r, true), array_slice($free, 0, 3))).'.'];
        } elseif ($cheap !== []) {
            $out[] = ['q' => 'Что есть дешевле 500 рублей?', 'a' => implode('; ', array_map(fn ($r) => $this->line($r, true, true), array_slice($cheap, 0, 3))).'.'];
        }

        if (($theme['when_question'] ?? '') !== '' && $day !== null) {
            $when = str_replace('{year}', (string) $day->year, $q('when_question'));
            $out[] = ['q' => $when, 'a' => $this->capital($this->dayMonth($day)).' '.$day->year.', '.self::WEEKDAYS[$day->isoWeekday()].'.'];
        }

        return $out;
    }

    /** «Кошмар на улице Кирова — The Last of Vavilone, 19:00»; с датой — для ответов, где дни вперемешку. */
    private function line(array $row, bool $withDay = false, bool $withPrice = false): string
    {
        $bits = array_filter([
            $withDay ? self::WEEKDAYS[Carbon::parse($row['date'], self::TZ)->isoWeekday()].' '.$this->dayMonth(Carbon::parse($row['date'], self::TZ)) : null,
            $row['venue'],
            $row['times'] !== [] ? implode(' и ', $row['times']) : null,
            $withPrice && $row['price_min'] !== null ? $row['price_min']."\u{00A0}₽" : null,
        ]);

        return $row['title'].($bits !== [] ? ' — '.implode(', ', $bits) : '');
    }

    /** @return list<array<string, mixed>> */
    private function next(array $theme, Carbon $now): array
    {
        return array_slice(array_values(array_filter(
            $this->calendar($now),
            fn ($o) => $o['page'] !== ($theme['page'] ?? null),
        )), 0, 4);
    }

    /**
     * Ближайшие поводы с датами по порядку — для «дальше в календаре» и списка страниц.
     *
     * @return list<array{page: string, title: string, emoji: string, date: string, badge: string, until: string, label: string, season: bool, phrase: string}>
     */
    public function calendar(Carbon $now): array
    {
        $now = $now->copy()->setTimezone(self::TZ);
        $out = [];
        foreach (OccasionCalendar::themes() as $t) {
            if (empty($t['page'])) {
                continue;
            }
            $day = BroadcastDigestComposer::occasionDate($t, $now);
            $window = OccasionCalendar::window($t, $now);
            if ($day === null || $window === null) {
                continue;
            }
            $out[] = [
                'page' => (string) $t['page'],
                'title' => (string) $t['title'],
                'emoji' => (string) ($t['emoji'] ?? ''),
                'date' => $day->toDateString(),
                // у праздника-сезона на корешке начало окна: ёлки с 19 декабря, а не 31-го
                'badge' => ($this->isSeason($t) ? $window[0] : $day)->toDateString(),
                'until' => $window[1]->toDateString(),
                'label' => $this->isSeason($t) ? $this->windowLabel($t, $window) : $this->kicker($t, $day, $window),
                'season' => $this->isSeason($t),
                'phrase' => (string) ($t['phrase'] ?? ''),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    /**
     * Три события праздника для ленты на главной: предстоящие из «Главного», а если их меньше трёх —
     * добор из программы по дням. Показываются по порядку времени, событие без часа — после часовых того же дня.
     *
     * @param  array<string, mixed>  $data  ответ build()
     * @return list<array{id: int, title: string, url: string, date: string, time: string|null}>
     */
    public static function program(array $data, int $limit = 3): array
    {
        $rows = [];
        $all = array_merge((array) $data['picks'], ...array_map(fn ($d) => (array) $d['events'], (array) $data['days']));
        foreach ($all as $row) {
            if (count($rows) >= $limit) {
                break;
            }
            if (! $row['is_past'] && ! isset($rows[$row['id']])) {
                $rows[$row['id']] = $row;
            }
        }
        $sortKey = fn ($r) => [$r['date'], ($r['times'][0] ?? null) === null, $r['times'][0] ?? ''];
        usort($rows, fn ($a, $b) => $sortKey($a) <=> $sortKey($b));

        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'url' => (string) $r['url'],
            'date' => (string) $r['date'],
            'time' => $r['times'][0] ?? null,
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $theme
     * @param  array{0: Carbon, 1: Carbon}|null  $window
     * @return array{title: string, description: string}
     */
    private function seo(string $headline, int $total, ?Carbon $day, ?array $window, array $theme, string $lead): array
    {
        $when = $window ? $this->windowLabel($theme, $window) : '';
        $title = $total > 0
            ? $headline.' — '.$total.' '.$this->plural($total, 'событие', 'события', 'событий').($day ? ', '.$this->dayMonth($day).' '.$day->year : '')
            : $headline.($day ? ' — '.$this->dayMonth($day).' '.$day->year : '');

        return [
            'title' => $title,
            'description' => $total > 0
                ? $headline.'. '.$lead.' Главное с ценами и вся программа по дням.'
                : $headline.' '.$when.': программа появится ближе к празднику.',
        ];
    }

    /** Над заголовком: день праздника, а у сезона вроде ёлок и каникул — всё окно. */
    private function kicker(array $theme, Carbon $day, array $window): string
    {
        if ($this->isSeason($theme)) {
            return $this->windowLabel($theme, $window);
        }

        return (isset($theme['hours']) ? 'вечер ' : '').$this->dayMonth($day).' · '.self::WEEKDAYS[$day->isoWeekday()];
    }

    /** Праздник длиной в неделю и больше: события растянуты, одного дня у него нет. */
    private function isSeason(array $theme): bool
    {
        return ! isset($theme['hours']) && (int) ($theme['window_before'] ?? 0) + (int) ($theme['window_after'] ?? 0) >= 6;
    }

    /** «31 октября»; у окна в несколько дней — «с 19 декабря по 10 января», у ночи — «вечер 31 декабря». */
    private function windowLabel(array $theme, array $window): string
    {
        [$from, $to] = $window;
        if (isset($theme['hours'])) {
            return 'вечер '.$this->dayMonth($from);
        }

        return $this->span($from, $to);
    }

    /** «31 октября», «с 5 по 8 марта», «с 19 декабря по 10 января». */
    private function span(Carbon $from, Carbon $to): string
    {
        return match (true) {
            $from->isSameDay($to) => $this->dayMonth($from),
            $from->month === $to->month => 'с '.$from->day.' по '.$this->dayMonth($to),
            default => 'с '.$this->dayMonth($from).' по '.$this->dayMonth($to),
        };
    }

    private function localDay(Event $e): string
    {
        return Carbon::parse($e->start_time)->setTimezone(self::TZ)->toDateString();
    }

    private function dayMonth(Carbon $d): string
    {
        return $d->day.' '.self::MONTHS[$d->month];
    }

    private function capital(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $d = $n % 10;

        return match (true) {
            $n > 10 && $n < 20 => $many,
            $d === 1 => $one,
            $d >= 2 && $d <= 4 => $few,
            default => $many,
        };
    }
}
