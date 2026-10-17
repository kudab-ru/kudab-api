<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Occasions\OccasionCalendar;
use App\Services\Telegram\BroadcastDigestComposer;
use App\Services\Telegram\BroadcastOccasions;
use App\Services\Telegram\EventCaptionBuilder;
use App\Services\Telegram\PickReason;
use App\Services\Telegram\Scoring\EventBroadcastScorer;
use App\Support\CalmCaps;
use App\Support\CityCase;
use App\Support\Telegram\KindEmoji;
use App\Support\Telegram\VenueName;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
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
    ) {}

    public function index(int $broadcastId): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($broadcastId);
        $now = Carbon::now(self::TZ);

        $rows = array_map(fn ($theme) => $this->row($broadcast, $theme, $now), OccasionCalendar::themes());
        usort($rows, fn ($a, $b) => strcmp($a['date'] ?? '9999', $b['date'] ?? '9999'));

        return response()->json(['data' => $rows]);
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

        return $base + [
            'state' => $state,
            'skipped_in_calendar' => $main?->error_message === BroadcastOccasions::SKIPPED_IN_CALENDAR,
            'date' => $day->toDateString(),
            'date_label' => $day->day.' '.self::MONTHS[$day->month],
            'weekday' => self::WEEKDAYS[$day->isoWeekday()],
            'window' => ['from' => $window[0]->toDateString(), 'to' => $window[1]->toDateString()],
            'post_at' => $postAt->copy()->setTimezone(self::TZ)->toIso8601String(),
            'book_from' => $planned->copy()->subDays(BroadcastOccasions::BOOK_AHEAD_DAYS)->toIso8601String(),
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
            'found' => in_array($state, ['posted', 'skipped'], true)
                ? null
                : $this->composer->poolSize($broadcast, $theme, $planned->copy()->max($now)),
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
            ->whereIn('e.id', $ids)->get(['e.id', 'e.title', 'e.start_time', 'e.time_precision', 'e.content_kind', 'e.address', 'v.name as venue'])
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
        $cap = max(1, (int) ($theme['kind_cap'] ?? 1));

        return array_map(function (array $c) use ($facts, $kindOf, $rosterVenues, $rosterKinds, $cap) {
            $id = (int) $c['id'];
            $kind = $kindOf($id);
            $why = match (true) {
                in_array($facts->get($id)?->venue_id, $rosterVenues, true) => 'та же площадка',
                $kind !== null && ($rosterKinds[$kind] ?? 0) >= $cap => $cap === 1 ? 'такой вид уже есть' : 'уже две строки этого вида',
                ($facts->get($id)?->audience ?? null) === 'kids' => 'детское',
                default => 'ниже по оценке',
            };

            return [
                'id' => $id,
                'title' => $this->title((string) $c['title']),
                'when' => $this->when($c['start_time'], 'datetime'),
                'venue' => VenueName::label($c['venue'] ?? null),
                'note' => $why,
            ];
        }, $rows);
    }

    /** Чем повод отбирает события — человеческими словами, под составом. */
    private function rule(array $theme): string
    {
        $words = array_map(fn ($w) => '«'.$w.'»', array_slice((array) ($theme['words'] ?? []), 0, 4));

        return implode(' · ', array_filter([
            ($theme['pick'] ?? '') === 'all' ? 'лучшее из всей афиши этих дней' : ($words !== [] ? 'ищет '.implode(', ', $words).' в названии и начале описания' : null),
            ! empty($theme['not_words']) ? 'без '.implode(', ', array_map(fn ($w) => '«'.$w.'»', (array) $theme['not_words'])) : null,
            isset($theme['hours']) ? 'с '.((array) $theme['hours'])[0].':00 до утра' : null,
            ($theme['pick'] ?? '') === 'words' ? 'праздник в названии выше' : null,
            (int) ($theme['kind_cap'] ?? 1) === 2 ? 'не больше двух строк одного вида' : 'по строке каждого вида',
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
