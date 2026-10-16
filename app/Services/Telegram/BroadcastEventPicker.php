<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Repositories\EventRepository;
use App\Support\Telegram\VenueName;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Панель выбора события в админке: все события ближайших дней или найденные поиском. Правила автомата
 * событие не прячут, а подписывают; кнопки нет только там, где откажет сама постановка.
 */
final class BroadcastEventPicker
{
    private const TZ = 'Europe/Moscow';

    /** День слота и два следующих. */
    public const DAYS = 3;

    public const MAX_DAYS = TelegramChatBroadcastService::CANDIDATE_HORIZON_DAYS;

    private const SEARCH_LIMIT = 40;

    /** Столько дат за месяц — это каждую неделю и чаще: квесты, еженедельный стендап, экскурсии по расписанию. */
    private const REPEATS_FOLD = 4;

    /** Повтор серии и двойник ищутся среди постов за этот срок и тех, что ещё в ленте. */
    private const RELATED_DAYS = 30;

    private const OPEN = [
        TelegramChatBroadcastItem::STATUS_PENDING,
        TelegramChatBroadcastItem::STATUS_PLANNED,
        TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
        TelegramChatBroadcastItem::STATUS_APPROVED,
        TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
    ];

    /** Вид для фильтров панели: по первичной теме или её родителю. */
    private const KINDS = [
        'music' => 'concert',
        'theatre' => 'theatre',
        'standup' => 'standup',
        'parties' => 'party',
        'festival' => 'festival',
    ];

    /** Лекции, мастер-классы, экскурсии: в своём дне идут после остального. */
    private const LOW = ['education', 'excursions'];

    public function __construct(
        private readonly EventRepository $events,
        private readonly BroadcastDigestComposer $composer,
    ) {}

    /**
     * Слот ленты. $occupant — пост, который сейчас стоит в слоте.
     *
     * @return array{rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function forSlot(TelegramChatBroadcast $broadcast, Carbon $at, ?TelegramChatBroadcastItem $occupant, ?string $query, int $days): array
    {
        $at = $at->copy()->utc();
        $days = max(1, min(self::MAX_DAYS, $days));
        $until = $at->copy()->setTimezone(self::TZ)->startOfDay()->addDays($days)->utc();
        $search = self::isSearch($query);

        $q = $this->cityEvents((int) $broadcast->chat?->city_id);
        if ($search) {
            $this->applySearch($q, (string) $query);
        } else {
            PostTiming::applyFits($q, $at, 0, strictRuns: true);
            $q->where('events.start_time', '<', $until)->orderBy('events.start_time')->orderBy('events.id');
        }
        $events = $this->load($q);

        $ids = $events->modelKeys();
        $taken = $this->takenBy((int) $broadcast->id, $ids, null);
        $related = $this->relatedShown((int) $broadcast->id, $events, null);
        $removed = $this->removedAt((int) $broadcast->id, $ids);
        $topics = $this->topics($ids);
        $repeats = $this->repeats($events);

        $rows = [];
        foreach ($events as $e) {
            $rows[] = $this->row($e, $at, $topics[$e->id] ?? [null, null], $repeats[(int) $e->event_group_id] ?? 1)
                + $this->slotVerdict($e, $at, $occupant, $taken[$e->id] ?? null, $related[$e->id] ?? null, $removed[$e->id] ?? null);
        }

        return ['rows' => $rows, 'meta' => $search
            ? ['search' => true, 'total' => count($rows), 'limit' => self::SEARCH_LIMIT]
            : [
                'search' => false,
                'total' => count($rows),
                'from' => $at->copy()->setTimezone(self::TZ)->toIso8601String(),
                'until' => $until->copy()->setTimezone(self::TZ)->toIso8601String(),
                'days' => $days,
                'more' => $days < self::MAX_DAYS,
            ]];
    }

    /**
     * Строка подборки: без запроса — события её рубрики, с запросом — весь город. $replacing — заменяемая
     * строка, без неё это добавление. null — рубрика записи неизвестна.
     *
     * @return array{rows: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    public function forDigest(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast, Carbon $publishAt, ?int $replacing, ?string $query): ?array
    {
        $publishAt = $publishAt->copy()->utc();
        $window = $this->composer->windowForItem($item, $publishAt);
        $cityId = (int) $broadcast->chat?->city_id;
        if ($window === null || ! $cityId) {
            return null;
        }

        $roster = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)
            ->pluck('event_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $pool = ($this->composer->poolForItem($item, $broadcast, $publishAt) ?? collect())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $search = self::isSearch($query);
        $q = $this->cityEvents($cityId);
        if ($search) {
            $this->applySearch($q, (string) $query);
        } else {
            $q->whereIn('events.id', array_values(array_diff($pool, $roster)))
                ->orderBy('events.start_time')
                ->orderBy('events.id');
        }
        $events = $this->load($q);
        $pool = array_flip($pool);

        $ids = $events->modelKeys();
        $taken = $this->takenBy((int) $broadcast->id, $ids, (int) $item->id);
        $notes = $this->composer->notesForEvents($item, $publishAt, $ids, $replacing);
        $topics = $this->topics($ids);
        $repeats = $this->repeats($events);
        [$since, $until] = $window;

        $rows = [];
        foreach ($events as $e) {
            $verdict = $this->digestVerdict($e, $publishAt, in_array((int) $e->id, $roster, true), $taken[$e->id] ?? null, $replacing === null);
            if ($verdict['action'] !== null) {
                $start = $e->start_time;
                $outside = $start === null || $start->gt($until) || (PostTiming::deadline($e) ?? $start)->lt($since ?? $publishAt);
                $more = array_filter([
                    match (true) {
                        $outside => 'не в днях подборки',
                        ! isset($pool[$e->id]) => 'автомат в эту подборку не взял бы',
                        default => null,
                    },
                    ...($notes[$e->id] ?? []),
                ]);
                $verdict['note'] = implode(' · ', array_filter([$verdict['note'], ...$more])) ?: null;
                $verdict['tone'] ??= $more !== [] ? 'muted' : null;
            }
            $rows[] = $this->row($e, $publishAt, $topics[$e->id] ?? [null, null], $repeats[(int) $e->event_group_id] ?? 1) + $verdict;
        }

        return ['rows' => $rows, 'meta' => ['search' => $search, 'total' => count($rows)]];
    }

    private function cityEvents(int $cityId): Builder
    {
        [$hiddenSql, $hiddenBindings] = Event::channelHiddenSql();

        // `active()` без имени таблицы, а поиск подключает площадки и организаторов с теми же колонками
        return Event::query()
            ->select('events.*')
            ->selectRaw($hiddenSql.' as channel_hidden', $hiddenBindings)
            ->where('events.status', 'active')
            ->whereNull('events.deleted_at')
            ->whereHas('community', fn ($c) => $c->where('city_id', $cityId))
            ->with('venue:id,name');
    }

    /** Ссылка с сайта находит своё событие, остальное ищется тем же правилом, что поиск на сайте. */
    private function applySearch(Builder $q, string $query): void
    {
        if (preg_match('~/events/(\d+)~', $query, $m)) {
            $q->whereKey((int) $m[1]);

            return;
        }

        $match = $this->events->applyTextSearch($q, $query);
        // о прошедшем пост уже не расскажет
        PostTiming::applyFits($q, Carbon::now()->utc(), 0, strictRuns: true);
        if ($match !== null) {
            $q->orderByRaw($match[0], $match[1]);
        }
        $q->orderBy('events.start_time')->limit(self::SEARCH_LIMIT);
    }

    private static function isSearch(?string $query): bool
    {
        return mb_strlen(trim((string) $query)) >= 2;
    }

    private function load(Builder $q): EloquentCollection
    {
        $events = $q->get();
        $this->events->hydrateImagesFor($events);

        return $events;
    }

    /**
     * @param  array{0: string|null, 1: string|null}  $topic  первичная тема и её родитель
     * @return array<string, mixed>
     */
    private function row(Event $e, Carbon $at, array $topic, int $repeats): array
    {
        [$slug, $parent] = $topic;
        $start = $e->start_time?->copy()->setTimezone(self::TZ);
        $runsUntil = PostTiming::runningUntil($e, $at)?->setTimezone(self::TZ);
        $images = $e->getAttribute('images');

        return [
            'id' => (int) $e->id,
            'title' => (string) $e->title,
            'start_time' => $start?->toIso8601String(),
            'end_time' => $e->end_time?->copy()->setTimezone(self::TZ)->toIso8601String(),
            // у события без времени в start_time полночь
            'has_time' => $e->time_precision !== 'date',
            'venue' => VenueName::label($e->venue?->name) ?: null,
            'price' => $this->price($e),
            'cover' => is_array($images) ? ($images[0] ?? null) : null,
            'kind' => self::KINDS[$slug] ?? self::KINDS[$parent] ?? null,
            'free' => in_array($e->price_status, ['free', 'donation'], true),
            'kids' => in_array($e->audience, ['kids', 'family'], true) || $slug === 'kids',
            'low' => in_array($slug, self::LOW, true) || in_array($parent, self::LOW, true),
            'folded' => $runsUntil !== null || $repeats >= self::REPEATS_FOLD,
            'tag' => match (true) {
                $runsUntil !== null => 'идёт до '.$runsUntil->locale('ru')->isoFormat('D MMMM'),
                $repeats >= self::REPEATS_FOLD => $repeats.' '.PickReason::plural($repeats, 'дата', 'даты', 'дат').' за месяц',
                $repeats <= 1 => 'разовое',
                default => null,
            },
        ];
    }

    private function price(Event $e): ?string
    {
        $min = $e->price_min !== null ? (int) $e->price_min : null;
        $max = $e->price_max !== null ? (int) $e->price_max : null;

        return match (true) {
            $e->price_status === 'free' => 'бесплатно',
            $e->price_status === 'donation' => 'свободный взнос',
            $min !== null && $max !== null && $min !== $max => $min.'–'.$max.' ₽',
            $min !== null && $min > 0 => 'от '.$min.' ₽',
            default => null,
        };
    }

    /**
     * Отказы — те, что вернёт `AdminBroadcastController::enqueue`; остальное только подписано.
     *
     * @return array{action: string|null, label: string, note: string|null, tone: string|null}
     */
    private function slotVerdict(Event $e, Carbon $at, ?TelegramChatBroadcastItem $occupant, ?object $taken, ?array $related, ?Carbon $removedAt): array
    {
        if ($taken !== null && $occupant !== null && (int) $taken->id === (int) $occupant->id) {
            return self::verdict(null, 'стоит здесь', 'этот пост сейчас в слоте', 'muted');
        }
        if ($taken !== null) {
            return $taken->posted_at !== null
                ? self::verdict(null, 'уже было', 'уже вышло в канале '.self::day($taken->posted_at), 'no')
                : self::verdict(null, 'в ленте', self::takenWhere($taken), 'muted');
        }

        $deadline = PostTiming::deadline($e);
        if ($deadline !== null && $deadline->lt($at)) {
            return self::verdict(null, 'поздно', $deadline->lt(Carbon::now()) ? 'уже прошло' : 'начнётся раньше поста', 'no');
        }

        $notes = [];
        if ($e->channel_hidden) {
            $notes[] = BroadcastDigestComposer::hiddenNote($e->content_kind);
        }
        if ($e->tickets_status === 'sold_out') {
            $notes[] = 'билеты распроданы';
        }
        if ($related !== null) {
            $notes[] = $related['note'];
        }
        if ($removedAt !== null) {
            $notes[] = 'вы снимали его из ленты '.self::day($removedAt);
        }
        $start = $e->start_time;
        if ($start !== null && $start->gte($at) && $start->lt($at->copy()->addHours(PostTiming::MIN_LEAD_HOURS))) {
            $hours = intdiv((int) $at->diffInMinutes($start), 60);
            $notes[] = ($hours < 1 ? 'до начала меньше часа' : 'до начала '.$hours.' '.PickReason::plural($hours, 'час', 'часа', 'часов'))
                .' — автомат такое не берёт';
        }

        return self::verdict('place', 'Поставить сюда', $notes === [] ? null : implode(' · ', $notes), $notes === [] ? null : 'warn');
    }

    /**
     * Отказы — те, что вернёт замена строки (`digestIncomingGuard`), только вышедшее в канал здесь
     * под запретом всегда: обменять вышедший пост нельзя.
     *
     * @return array{action: string|null, label: string, note: string|null, tone: string|null}
     */
    private function digestVerdict(Event $e, Carbon $publishAt, bool $inRoster, ?object $taken, bool $adding): array
    {
        if ($inRoster) {
            return self::verdict(null, 'в подборке', 'уже в этой подборке', 'muted');
        }

        $deadline = PostTiming::deadline($e);
        if ($deadline !== null && $deadline->lt($publishAt)) {
            return self::verdict(null, 'поздно', $deadline->lt(Carbon::now()) ? 'уже прошло' : 'начнётся раньше, чем выйдет подборка', 'no');
        }

        if ($taken !== null) {
            if ($taken->posted_at !== null) {
                return self::verdict(null, 'уже было', 'уже вышло в канале '.self::day($taken->posted_at), 'no');
            }
            if ($taken->kind !== TelegramChatBroadcastItem::KIND_EVENT) {
                return self::verdict(null, 'занято', self::takenWhere($taken), 'muted');
            }
            if ($taken->is_pinned) {
                return self::verdict(null, 'закреплён', self::takenWhere($taken).', пост закреплён', 'muted');
            }
            if ($taken->claimed_at !== null
                && Carbon::parse($taken->claimed_at)->gt(Carbon::now()->subSeconds(TelegramChatBroadcastService::CLAIM_LEASE_SECONDS))) {
                return self::verdict(null, 'уходит', 'свой пост уже уходит в канал', 'muted');
            }

            return self::verdict('swap', 'Обменять', self::takenWhere($taken).' — при обмене пост снимется', 'warn');
        }

        return self::verdict('place', $adding ? 'Добавить' : 'Поставить', null, null);
    }

    /** @return array{action: string|null, label: string, note: string|null, tone: string|null} */
    private static function verdict(?string $action, string $label, ?string $note, ?string $tone): array
    {
        return ['action' => $action, 'label' => $label, 'note' => $note, 'tone' => $tone];
    }

    private static function takenWhere(object $taken): string
    {
        $what = $taken->kind === TelegramChatBroadcastItem::KIND_EVENT ? 'стоит в ленте' : 'в подборке';

        return $taken->publish_at !== null ? $what.': '.self::when($taken->publish_at) : $what.' без даты';
    }

    private static function day(mixed $at): string
    {
        return Carbon::parse($at)->setTimezone(self::TZ)->locale('ru')->isoFormat('D MMMM');
    }

    private static function when(mixed $at): string
    {
        return Carbon::parse($at)->setTimezone(self::TZ)->locale('ru')->isoFormat('dd D MMM, HH:mm');
    }

    /**
     * Последний пост канала, который называет событие: вышедший или стоящий в ленте. Условие то же,
     * что у `AdminBroadcastController::eventTakenByAnotherPost`, правятся вместе.
     *
     * @param  list<int>  $eventIds
     * @return array<int, object>
     */
    private function takenBy(int $broadcastId, array $eventIds, ?int $exceptItemId): array
    {
        if ($eventIds === []) {
            return [];
        }

        $out = [];
        foreach (DB::table('telegram.chat_broadcast_items as i')
            ->join('telegram.chat_broadcast_item_events as l', 'l.item_id', '=', 'i.id')
            ->where('i.broadcast_id', $broadcastId)
            ->whereIn('l.event_id', $eventIds)
            ->when($exceptItemId !== null, fn ($q) => $q->where('i.id', '<>', $exceptItemId))
            ->where(fn ($q) => $q->whereNotNull('i.posted_at')->orWhereIn('i.status', self::OPEN))
            ->orderByRaw('COALESCE(i.posted_at, i.publish_at, i.created_at) DESC')
            ->get(['l.event_id', 'i.id', 'i.kind', 'i.posted_at', 'i.publish_at', 'i.is_pinned', 'i.claimed_at']) as $row) {
            $out[(int) $row->event_id] ??= $row;
        }

        return $out;
    }

    /**
     * Другая дата той же серии или двойник с той же площадкой и минутой, о которых канал уже
     * рассказывал. Постановка их не проверяет, а подписчик увидит повтор.
     *
     * @return array<int, array{note: string}>
     */
    private function relatedShown(int $broadcastId, EloquentCollection $events, ?int $exceptItemId): array
    {
        $groups = $events->pluck('event_group_id')->filter()->unique()->values()->all();
        $venues = $events->pluck('venue_id')->filter()->unique()->values()->all();
        if ($groups === [] && $venues === []) {
            return [];
        }

        $byGroup = [];
        $byTwin = [];
        foreach (DB::table('telegram.chat_broadcast_items as i')
            ->join('telegram.chat_broadcast_item_events as l', 'l.item_id', '=', 'i.id')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('i.broadcast_id', $broadcastId)
            ->when($exceptItemId !== null, fn ($q) => $q->where('i.id', '<>', $exceptItemId))
            ->where(fn ($q) => $q
                ->where('i.posted_at', '>=', Carbon::now()->subDays(self::RELATED_DAYS)->utc())
                ->orWhere(fn ($x) => $x->whereNull('i.posted_at')->whereIn('i.status', self::OPEN)))
            ->where(fn ($q) => $q->whereIn('e.event_group_id', $groups ?: [0])->orWhereIn('e.venue_id', $venues ?: [0]))
            ->orderByRaw('COALESCE(i.posted_at, i.publish_at, i.created_at) DESC')
            ->get(['e.id as event_id', 'e.event_group_id', 'e.venue_id', 'e.start_time', 'i.kind', 'i.posted_at', 'i.publish_at']) as $row) {
            if ($row->event_group_id !== null) {
                $byGroup[(int) $row->event_group_id] ??= $row;
            }
            if ($row->venue_id !== null && $row->start_time !== null) {
                $byTwin[$row->venue_id.'|'.Carbon::parse($row->start_time)->utc()->format('Y-m-d H:i')] ??= $row;
            }
        }

        $out = [];
        foreach ($events as $e) {
            $sameGroup = $e->event_group_id !== null ? ($byGroup[(int) $e->event_group_id] ?? null) : null;
            $twin = $e->venue_id !== null && $e->start_time !== null
                ? ($byTwin[$e->venue_id.'|'.$e->start_time->copy()->utc()->format('Y-m-d H:i')] ?? null)
                : null;
            $twin = $twin !== null && (int) $twin->event_id !== (int) $e->id ? $twin : null;
            $hit = $twin ?? ($sameGroup !== null && (int) $sameGroup->event_id !== (int) $e->id ? $sameGroup : null);
            if ($hit === null) {
                continue;
            }

            $what = $twin !== null ? 'такое же событие из другого источника' : 'другая дата';
            $out[(int) $e->id] = ['note' => $hit->posted_at !== null
                ? $what.' уже было в канале '.self::day($hit->posted_at)
                : $what.' '.self::takenWhere($hit)];
        }

        return $out;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, Carbon> когда событие снимали из ленты руками
     */
    private function removedAt(int $broadcastId, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $out = [];
        foreach (TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereIn('event_id', $eventIds)
            ->whereIn('status', [TelegramChatBroadcastItem::STATUS_SKIPPED, TelegramChatBroadcastItem::STATUS_REJECTED])
            ->get(['event_id', 'pick_meta']) as $item) {
            $at = ((array) ($item->pick_meta ?? []))[TelegramChatBroadcastItem::PICK_REMOVED_AT] ?? null;
            if ($at !== null) {
                $out[(int) $item->event_id] = Carbon::parse($at);
            }
        }

        return $out;
    }

    /**
     * Первичная тема с меньшим id, как и во всей рассылке, и её родитель.
     *
     * @param  list<int>  $eventIds
     * @return array<int, array{0: string, 1: string|null}>
     */
    private function topics(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $out = [];
        foreach (DB::table('event_interest as ei')
            ->join('interests as i', 'i.id', '=', 'ei.interest_id')
            ->leftJoin('interests as p', 'p.id', '=', 'i.parent_id')
            ->whereIn('ei.event_id', $eventIds)
            ->where('ei.rank', 0)
            ->orderBy('i.id')
            ->get(['ei.event_id', 'i.slug', 'p.slug as parent']) as $row) {
            $out[(int) $row->event_id] ??= [(string) $row->slug, $row->parent !== null ? (string) $row->parent : null];
        }

        return $out;
    }

    /** @return array<int, int> серия → сколько у неё дат в ближайший месяц */
    private function repeats(EloquentCollection $events): array
    {
        $groups = $events->pluck('event_group_id')->filter()->unique()->values()->all();
        if ($groups === []) {
            return [];
        }

        return DB::table('events')
            ->whereIn('event_group_id', $groups)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereBetween('start_time', [Carbon::now()->utc(), Carbon::now()->addDays(30)->utc()])
            ->groupBy('event_group_id')
            ->selectRaw('event_group_id, count(*) as n')
            ->pluck('n', 'event_group_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
