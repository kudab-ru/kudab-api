<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Event;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Occasions\OccasionCalendar;
use App\Services\Occasions\OccasionPage;
use App\Services\Occasions\OccasionPromo;
use App\Services\Telegram\BroadcastDigestComposer;
use App\Services\Telegram\BroadcastEventPicker;
use App\Services\Telegram\BroadcastOccasions;
use App\Services\Telegram\DigestRosterJournal;
use App\Services\Telegram\EventCaptionBuilder;
use App\Services\Telegram\PickReason;
use App\Services\Telegram\Scoring\EventBroadcastScorer;
use App\Services\Telegram\TelegramChatBroadcastService;
use App\Support\CalmCaps;
use App\Support\CityCase;
use App\Support\EventStart;
use App\Support\Telegram\KindEmoji;
use App\Support\Telegram\VenueName;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Календарь поводов канала в админке: когда пост, что в составе, что ещё нашлось и почему не взято. */
final class AdminOccasionsController extends Controller
{
    private const TZ = 'Europe/Moscow';

    private const WEEKDAYS = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];

    private const MONTHS = [1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'];

    /** Пропустить можно только то, что ещё не вышло. */
    private const SKIPPABLE = ['waiting', 'booked', 'composed'];

    public function __construct(
        private readonly BroadcastDigestComposer $composer,
        private readonly BroadcastOccasions $occasions,
        private readonly EventBroadcastScorer $scorer,
        private readonly TelegramChatBroadcastService $broadcasts,
        private readonly OccasionPage $page,
        private readonly BroadcastEventPicker $picker,
        private readonly DigestRosterJournal $journal,
    ) {}

    /** Программа праздника, как её показывает сайт, и что из неё убрано руками. */
    public function program(int $broadcastId, string $slug): JsonResponse
    {
        [$theme, $city, $window] = $this->programScope($broadcastId, $slug);
        [$added, $removed] = $this->page->handPicks($slug);
        $rows = $this->page->assemble($theme, $city, Carbon::now(self::TZ), $window, null, null)['rows'];

        return response()->json(['data' => [
            'rows' => array_map(fn (array $r) => [
                'id' => $r['id'],
                'title' => $r['title'],
                'when' => $this->when($r['start_at'], $r['time_precision']),
                'venue' => $r['venue'],
                'by_hand' => in_array($r['id'], $added, true),
            ], $rows),
            'removed' => $this->eventLines($removed, (string) $city->name),
        ]]);
    }

    /** Что добавить в программу: см. `BroadcastEventPicker::forOccasion`. */
    public function picker(Request $request, int $broadcastId, string $slug): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:300']]);
        [$theme, $city, $window] = $this->programScope($broadcastId, $slug);
        $out = $this->picker->forOccasion((int) $city->id, $window, $this->page->programIds($theme, $city, $window), $data['q'] ?? null);
        $site = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');

        return response()->json([
            'data' => array_map(fn (array $row) => $row + ['url' => $site.'/events/'.$row['id']], $out['rows']),
            'meta' => $out['meta'],
        ]);
    }

    /** Поставить событие в программу праздника вопреки правилам повода. */
    public function include(Request $request, int $broadcastId, string $slug): JsonResponse
    {
        return $this->decide($request, $broadcastId, $slug, true);
    }

    /** Убрать событие из программы праздника. */
    public function exclude(Request $request, int $broadcastId, string $slug): JsonResponse
    {
        return $this->decide($request, $broadcastId, $slug, false);
    }

    /**
     * Хранится только расхождение с правилами: событие, которое правила и так берут, «добавить» лишь
     * снимает прежнее «убрано», а добавленное руками «убрать» снимает отметку.
     */
    private function decide(Request $request, int $broadcastId, string $slug, bool $include): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        [$theme, $city, $window] = $this->programScope($broadcastId, $slug);
        $eventId = (int) $data['event_id'];

        $event = Event::query()->where('city_id', $city->id)->where('status', 'active')->find($eventId);
        $start = $event !== null ? EventStart::of($event) : null;
        if ($include && ($start === null || $start->lt($window[0]) || $start->gt($window[1]))) {
            return response()->json(['error' => 'Событие не в днях праздника — на странице его не будет.'], 422);
        }

        $rule = $this->page->brokenRule($theme, $eventId);
        $byRules = $rule === null;
        if ($include === $byRules) {
            DB::table('occasion_picks')->where('slug', $slug)->where('event_id', $eventId)->delete();
        } else {
            DB::table('occasion_picks')->updateOrInsert(
                ['slug' => $slug, 'event_id' => $eventId],
                ['included' => $include, 'user_id' => auth()->id(), 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $this->journal->occasionEdited(
            $slug,
            $include ? DigestRosterJournal::ADD : DigestRosterJournal::REMOVE,
            $eventId,
            $data['reason'] ?? null,
            $include && ! $byRules ? ['rule' => (string) $rule, 'note' => self::ruleNote((string) $rule)] : null,
        );
        OccasionPromo::forgetPage($city, $theme);

        return $this->program($broadcastId, $slug);
    }

    /** Правило повода, которое не прошло событие, словами для журнала; имена — `OccasionPage::rules`. */
    private static function ruleNote(string $rule): string
    {
        return $rule === 'hidden'
            ? 'сайт такое не показывает: источник в чёрном списке или вид события скрыт'
            : (BroadcastDigestComposer::MISS_NOTES[$rule] ?? $rule);
    }

    /** @return array{0: array<string, mixed>, 1: City, 2: array{0: Carbon, 1: Carbon}} */
    private function programScope(int $broadcastId, string $slug): array
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($broadcastId);
        $theme = collect(OccasionCalendar::themes())->firstWhere('slug', $slug);
        abort_if($theme === null || empty($theme['page']), 404);

        $city = $broadcast->chat?->city;
        $window = OccasionCalendar::window($theme, Carbon::now(self::TZ));
        if ($city === null || $window === null) {
            abort(response()->json(['error' => 'У праздника нет дат в этом году или у канала не задан город.'], 422));
        }

        return [$theme, $city, $window];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, title: string, when: string, venue: string|null}>
     */
    private function eventLines(array $ids, string $city): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('events as e')->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->whereIn('e.id', $ids)
            ->whereNull('e.deleted_at')
            ->orderByRaw(EventStart::sql('e'))
            ->selectRaw(EventStart::sql('e').' as start_time')
            ->addSelect(['e.id', 'e.title', 'e.time_precision', 'e.address', 'v.name as venue'])
            ->get()
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'title' => $this->title((string) $e->title),
                'when' => $this->when($e->start_time, (string) $e->time_precision),
                'venue' => $e->venue !== null ? VenueName::label($e->venue) : EventCaptionBuilder::placeFromAddress((string) $e->address, $city),
            ])
            ->all();
    }

    public function index(int $broadcastId): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($broadcastId);
        $now = Carbon::now(self::TZ);

        $rows = array_map(fn ($theme) => $this->row($broadcast, $theme, $now), OccasionCalendar::themes());
        usort($rows, fn ($a, $b) => strcmp($a['date'] ?? '9999', $b['date'] ?? '9999'));

        return response()->json(['data' => $rows]);
    }

    /**
     * Собрать пост к празднику сейчас, не дожидаясь брони за неделю: запись встаёт на время поста,
     * состав и текст собираются сразу. Не набирается — запись не остаётся.
     */
    public function book(int $broadcastId, string $slug): JsonResponse
    {
        [$broadcast, $theme, $day] = $this->resolve($broadcastId, $slug);
        $now = Carbon::now(self::TZ);
        if ($now->gte($day->copy()->subDay())) {
            return response()->json(['error' => 'До праздника меньше суток — ставить пост поздно.'], 422);
        }
        $existing = $this->occasions->occasionItems($broadcast, $theme, $day)->get();
        if ($existing->isNotEmpty()) {
            return response()->json(['error' => $existing->contains('error_message', BroadcastOccasions::SKIPPED_IN_CALENDAR)
                ? 'Праздник пропущен в этом году — сначала верните его.'
                : 'Пост к этому празднику уже есть.'], 409);
        }

        $at = BroadcastOccasions::plannedAt($theme, $day)->max($now->copy()->addMinutes(30));
        DB::beginTransaction();
        try {
            $item = $this->occasions->book($broadcast, $theme, $at);
            $draft = $this->composer->compose($broadcast, $at, $item, (string) $theme['slug']);
            if ($draft === null) {
                DB::rollBack();

                return response()->json(['error' => 'Пока не набирается: к празднику нужно хотя бы '
                    .(int) ($theme['min_events'] ?? 3).' события на '.(int) ($theme['min_venues'] ?? 2).' площадках.'], 422);
            }
            $this->broadcasts->applyDigestDraft($item, $draft);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return response()->json(['data' => $this->row($broadcast, $theme, Carbon::now(self::TZ))]);
    }

    /** Пропустить праздник в этом году: снять бронь или, если её ещё нет, поставить запись, которая её не пустит. */
    public function skip(int $broadcastId, string $slug): JsonResponse
    {
        [$broadcast, $theme, $day] = $this->resolve($broadcastId, $slug);
        $row = $this->row($broadcast, $theme, Carbon::now(self::TZ));
        if (! in_array($row['state'], self::SKIPPABLE, true)) {
            return response()->json(['error' => 'Пост к празднику уже вышел или снят.'], 422);
        }

        $item = $row['item'] !== null ? TelegramChatBroadcastItem::query()->find($row['item']['id']) : null;
        if ($item === null) {
            $item = new TelegramChatBroadcastItem;
            $item->broadcast_id = $broadcast->id;
            $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
            $item->is_off_grid = true;
            $item->publish_at = BroadcastOccasions::plannedAt($theme, $day)->utc();
            $item->digest_meta = ['theme_wanted' => (string) $theme['slug']];
            $item->pick_meta = PickReason::meta(PickReason::BY_DIGEST, 'occasion');
        }
        $item->status = TelegramChatBroadcastItem::STATUS_SKIPPED;
        $item->error_message = BroadcastOccasions::SKIPPED_IN_CALENDAR;
        $item->save();

        return response()->json(['data' => $this->row($broadcast, $theme, Carbon::now(self::TZ))]);
    }

    /** Вернуть пропущенный в календаре: пустую запись-заглушку убрать, собранный черновик вернуть в ленту. */
    public function unskip(int $broadcastId, string $slug): JsonResponse
    {
        [$broadcast, $theme, $day] = $this->resolve($broadcastId, $slug);
        $item = $this->occasions->occasionItems($broadcast, $theme, $day)
            ->where('status', TelegramChatBroadcastItem::STATUS_SKIPPED)
            ->where('error_message', BroadcastOccasions::SKIPPED_IN_CALENDAR)
            ->latest('id')
            ->first();
        abort_if($item === null, 404);

        $hasRoster = DB::table('telegram.chat_broadcast_item_events')->where('item_id', $item->id)->exists();
        if ($hasRoster) {
            $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
            $item->error_message = null;
            $item->save();
        } else {
            $item->delete();
        }

        return response()->json(['data' => $this->row($broadcast, $theme, Carbon::now(self::TZ))]);
    }

    /** Вписать дату праздника, который назначают каждый год заново; конец — у школьных каникул. */
    public function addDate(Request $request, string $slug): JsonResponse
    {
        $theme = $this->floatingTheme($slug);
        $validator = validator($request->all(), [
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [
            'starts_on.required' => 'Нужна дата начала.',
            'starts_on.after_or_equal' => 'Эта дата уже прошла.',
            'ends_on.after_or_equal' => 'Конец раньше начала.',
            '*.date_format' => 'Дата в виде год-месяц-день.',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }
        $v = $validator->validated();
        if (! empty($v['ends_on']) && Carbon::parse($v['starts_on'])->diffInDays(Carbon::parse($v['ends_on'])) > 31) {
            return response()->json(['error' => 'Больше месяца — это уже не праздник: проверьте даты.'], 422);
        }

        DB::table('occasion_dates')->updateOrInsert(
            ['slug' => $theme['slug'], 'starts_on' => $v['starts_on']],
            ['ends_on' => $v['ends_on'] ?? null, 'updated_at' => now(), 'created_at' => now()],
        );
        OccasionCalendar::forgetFloating((string) $theme['slug']);

        return response()->json(['data' => $this->dates((string) $theme['slug'])]);
    }

    public function removeDate(string $slug, string $date): JsonResponse
    {
        $theme = $this->floatingTheme($slug);
        DB::table('occasion_dates')->where('slug', $theme['slug'])->where('starts_on', $date)->delete();
        OccasionCalendar::forgetFloating((string) $theme['slug']);

        return response()->json(['data' => $this->dates((string) $theme['slug'])]);
    }

    /** @return array<string, mixed> */
    private function floatingTheme(string $slug): array
    {
        $theme = collect(OccasionCalendar::themes())->firstWhere('slug', $slug);
        abort_if($theme === null || ($theme['occasion'] ?? null) !== 'floating', 404);

        return $theme;
    }

    /** @return list<array{starts_on: string, ends_on: string|null}> */
    private function dates(string $slug): array
    {
        $today = Carbon::now(self::TZ)->toDateString();

        return array_values(array_map(
            fn ($d) => ['starts_on' => $d[0], 'ends_on' => $d[1]],
            array_filter(OccasionCalendar::floating($slug), fn ($d) => ($d[1] ?? $d[0]) >= $today),
        ));
    }

    /** @return array{0: TelegramChatBroadcast, 1: array<string, mixed>, 2: Carbon} */
    private function resolve(int $broadcastId, string $slug): array
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($broadcastId);
        $theme = collect(OccasionCalendar::themes())->firstWhere('slug', $slug);
        abort_if($theme === null, 404);
        $day = BroadcastDigestComposer::occasionDate($theme, Carbon::now(self::TZ));
        if ($day === null) {
            abort(response()->json(['error' => 'У праздника нет даты в этом году.'], 422));
        }

        return [$broadcast, $theme, $day];
    }

    /** @param  array<string, mixed>  $theme */
    private function row(TelegramChatBroadcast $broadcast, array $theme, Carbon $now): array
    {
        $citySlug = $broadcast->chat?->city?->slug;
        $cityIn = CityCase::prepositional($citySlug);
        $base = [
            'slug' => (string) $theme['slug'],
            'page' => $theme['page'] ?? null,
            'title' => (string) $theme['title'],
            'emoji' => (string) ($theme['emoji'] ?? ''),
            'headline' => trim((string) preg_replace('/\s+(?=[:,.]|$)/u', '',
                str_replace('{city}', $cityIn !== null ? 'в '.$cityIn : '', (string) ($theme['headline'] ?? $theme['title'])))),
            'rule' => $this->rule($theme),
            'page_url' => isset($theme['page']) && $citySlug
                ? rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/').'/afisha/'.$citySlug.'/'.$theme['page']
                : null,
            'floating' => ($theme['occasion'] ?? null) === 'floating',
            'dates' => ($theme['occasion'] ?? null) === 'floating' ? $this->dates((string) $theme['slug']) : [],
        ];

        $day = BroadcastDigestComposer::occasionDate($theme, $now);
        $window = OccasionCalendar::window($theme, $now);
        if ($day === null || $window === null) {
            return $base + ['state' => 'no_date', 'date' => null, 'item' => null, 'reminder' => null,
                'found' => null, 'roster' => [], 'more' => []];
        }

        $planned = BroadcastOccasions::plannedAt($theme, $day);
        $items = $this->occasions->occasionItems($broadcast, $theme, $day)->orderByDesc('id')->get();
        $reminder = $items->first(fn ($i) => ! empty(($i->digest_meta ?? [])['reply_to']));
        $mains = $items->filter(fn ($i) => empty(($i->digest_meta ?? [])['reply_to']));
        $main = $mains->first(fn ($i) => $i->status !== TelegramChatBroadcastItem::STATUS_SKIPPED) ?? $mains->first();
        $roster = $main === null ? [] : DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $main->id)->orderBy('position')->pluck('event_id')->map(fn ($v) => (int) $v)->all();

        $state = match (true) {
            $main === null => 'waiting',
            in_array($main->status, [TelegramChatBroadcastItem::STATUS_SKIPPED, TelegramChatBroadcastItem::STATUS_REJECTED, TelegramChatBroadcastItem::STATUS_WITHDRAWN], true) => 'skipped',
            $main->status === TelegramChatBroadcastItem::STATUS_POSTED => 'posted',
            $roster !== [] => 'composed',
            default => 'booked',
        };
        $postAt = $main?->publish_at ? Carbon::parse($main->publish_at) : $planned;
        $found = in_array($state, ['posted', 'skipped'], true)
            ? null
            : $this->composer->poolSize($broadcast, $theme, $planned->copy()->max($now));

        return $base + [
            'state' => $state,
            'skipped_in_calendar' => $main?->error_message === BroadcastOccasions::SKIPPED_IN_CALENDAR,
            'date' => $day->toDateString(),
            'date_label' => $day->day.' '.self::MONTHS[$day->month],
            'weekday' => self::WEEKDAYS[$day->isoWeekday()],
            'window' => ['from' => $window[0]->toDateString(), 'to' => $window[1]->toDateString()],
            'post_at' => $postAt->copy()->setTimezone(self::TZ)->toIso8601String(),
            'book_from' => $planned->copy()->subDays(BroadcastOccasions::BOOK_AHEAD_DAYS)->toIso8601String(),
            // пустую бронь состав и текст получают за `prepare_hours` до выхода, см. PrepareDigestsCommand
            'compose_at' => $postAt->copy()->subHours((int) config('broadcast_digest.prepare_hours', 18))
                ->setTimezone(self::TZ)->toIso8601String(),
            'reminder_at' => trim((string) ($theme['reminder'] ?? '')) !== ''
                ? $day->copy()->setTime((int) ($theme['reminder_hour'] ?? 12), 0)->toIso8601String()
                : null,
            'item' => $main === null ? null : [
                'id' => (int) $main->id,
                'status' => (string) $main->status,
                'posted_at' => $main->posted_at ? Carbon::parse($main->posted_at)->setTimezone(self::TZ)->toIso8601String() : null,
                'message_id' => $main->message_id !== null ? (int) $main->message_id : null,
                'has_text' => ! empty(($main->digest_meta ?? [])['hooks']),
            ],
            'reminder' => $reminder === null ? null : [
                'id' => (int) $reminder->id,
                'status' => (string) $reminder->status,
            ],
            'found' => $found,
            // хватает ли событий на пост; тот же порог, что у сборки
            'gathers' => $found !== null && $found['events'] >= (int) ($theme['min_events'] ?? 3)
                && $found['venues'] >= (int) ($theme['min_venues'] ?? 2),
            'roster' => $this->roster($main, $roster, (string) ($broadcast->chat?->city?->name ?? '')),
            'more' => $state === 'composed' ? $this->more($broadcast, $theme, $main, $roster, $postAt) : [],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function roster(?TelegramChatBroadcastItem $item, array $ids, string $city): array
    {
        if ($item === null || $ids === []) {
            return [];
        }

        $emoji = (array) (($item->digest_meta ?? [])['emoji'] ?? []);
        $slugs = DB::table('event_interest as ei')->join('interests as i', 'i.id', '=', 'ei.interest_id')
            ->whereIn('ei.event_id', $ids)->orderBy('ei.rank')->get(['ei.event_id', 'i.slug'])
            ->groupBy('event_id')->map(fn ($g) => $g->pluck('slug')->all());
        $events = DB::table('events as e')->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->whereIn('e.id', $ids)->selectRaw(EventStart::sql('e').' as start_time')
            ->addSelect(['e.id', 'e.title', 'e.time_precision', 'e.content_kind', 'e.address', 'v.name as venue'])->get()
            ->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $e = $events->get($id);
            if ($e === null) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'title' => $this->title((string) $e->title),
                'when' => $this->when($e->start_time, (string) $e->time_precision),
                'venue' => $e->venue !== null ? VenueName::label($e->venue) : EventCaptionBuilder::placeFromAddress((string) $e->address, $city),
                'emoji' => trim((string) ($emoji[(string) $id] ?? '')) ?: KindEmoji::for([
                    'title' => $e->title, 'interest_slugs' => $slugs->get($id, []), 'content_kind' => $e->content_kind,
                ], suppressWhenTitleSaysIt: false),
            ];
        }

        return $out;
    }

    /**
     * Что ещё нашлось и почему не в посте. Причины свои: у повода нет правила «одна строка на день»,
     * поэтому пометки панели замены про день здесь врали бы.
     *
     * @param  list<int>  $roster
     * @return list<array<string, mixed>>
     */
    private function more(TelegramChatBroadcast $broadcast, array $theme, TelegramChatBroadcastItem $item, array $roster, Carbon $publishAt): array
    {
        $rows = array_slice((array) (($this->composer->candidatesForItem($item, $broadcast, $publishAt) ?? [])['rows'] ?? []), 0, 5);
        $ids = array_merge($roster, array_map(fn ($c) => (int) $c['id'], $rows));
        $facts = DB::table('events')->whereIn('id', $ids)->get(['id', 'venue_id', 'audience'])->keyBy('id');
        $this->scorer->prepare($facts->all());
        $kindOf = fn (int $id) => $this->scorer->explain((object) ['id' => $id])['kind'];
        $rosterVenues = array_filter(array_map(fn ($id) => $facts->get($id)?->venue_id, $roster));
        $rosterKinds = array_count_values(array_filter(array_map($kindOf, $roster)));
        $caps = (array) ($theme['kind_caps'] ?? []);

        return array_map(function (array $c) use ($facts, $kindOf, $rosterVenues, $rosterKinds, $theme, $caps) {
            $id = (int) $c['id'];
            $kind = $kindOf($id);
            $cap = (int) ($caps[$kind] ?? max(1, (int) ($theme['kind_cap'] ?? 1)));
            $why = match (true) {
                in_array($facts->get($id)?->venue_id, $rosterVenues, true) => 'та же площадка',
                $kind !== null && ($rosterKinds[$kind] ?? 0) >= $cap => $cap === 1 ? 'такой вид уже есть' : 'уже две строки этого вида',
                ($facts->get($id)?->audience ?? null) === 'kids' => 'детское',
                default => 'ниже по оценке',
            };

            return [
                'id' => $id,
                'title' => $this->title((string) $c['title']),
                'when' => $this->when($c['start_time'], $c['has_time'] ? 'datetime' : 'date'),
                'venue' => VenueName::label($c['venue'] ?? null),
                'note' => $why,
            ];
        }, $rows);
    }

    /** Чем повод отбирает события — человеческими словами, под составом. */
    private function rule(array $theme): string
    {
        $words = array_map(fn ($w) => '«'.$w.'»', array_slice((array) ($theme['words'] ?? []), 0, 4));
        $single = array_intersect_key(['quiz-games' => 'квиз', 'kids' => 'детское'], array_filter((array) ($theme['kind_caps'] ?? []), fn ($n) => (int) $n === 1));

        return implode(' · ', array_filter([
            ($theme['pick'] ?? '') === 'all' ? 'лучшее из всей афиши этих дней' : ($words !== [] ? 'ищет '.implode(', ', $words).' в названии и начале описания' : null),
            ! empty($theme['not_words']) ? 'без '.implode(', ', array_map(fn ($w) => '«'.$w.'»', (array) $theme['not_words'])) : null,
            isset($theme['hours']) ? 'с '.((array) $theme['hours'])[0].':00 до утра' : null,
            ($theme['pick'] ?? '') === 'words' ? 'праздник в названии выше' : null,
            (int) ($theme['kind_cap'] ?? 1) === 2 ? 'не больше двух строк одного вида' : 'по строке каждого вида',
            $single !== [] ? implode(' и ', $single).' — не больше строки' : null,
        ]));
    }

    private function title(string $title): string
    {
        return (string) preg_replace('/"([^"]{1,80})"/u', '«$1»', CalmCaps::title($title));
    }

    private function when(mixed $start, string $precision): string
    {
        if ($start === null) {
            return '';
        }
        $at = Carbon::parse($start)->setTimezone(self::TZ);
        $day = self::WEEKDAYS[$at->isoWeekday()].' '.$at->day.' '.mb_substr(self::MONTHS[$at->month], 0, 3);

        return $precision === 'date' ? $day : $day.', '.$at->format('H:i');
    }
}
