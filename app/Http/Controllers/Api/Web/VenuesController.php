<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Resources\WebEventResource;
use App\Http\Resources\WebVenueDetailResource;
use App\Http\Resources\WebVenueResource;
use App\Models\Event;
use App\Models\Venue;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Public-frontend venue endpoints (PR4):
 *   GET /api/web/venues             — каталог с фильтрами;
 *   GET /api/web/venues/map         — geojson FeatureCollection для карты;
 *   GET /api/web/venues/{id}        — детальная карточка + future events.
 *
 * `cover_image_url` (A4(a)) — proxy картинки первого event'а через
 * EventSource.images. Один subquery на запрос, без N+1.
 *
 * `next_event` / `upcoming_total` — обогащение карточки каталога (Vue-порт
 * /venues): строка «ближайшее» и состояние «сегодня / есть предстоящие /
 * пока без афиши». Один window-запрос на страницу, см. attachUpcoming().
 */
class VenuesController extends Controller
{
    public function __construct(private readonly EventService $events)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $cityId = $this->resolveCityId($request);

        $perPage = max(1, min((int) $request->input('per_page', 20), 50));
        $q       = trim((string) $request->input('q', ''));
        $kind    = trim((string) $request->input('kind', ''));

        $query = $this->baseQuery()
            ->when($cityId !== null, fn ($qq) => $qq->where('venues.city_id', $cityId))
            ->when($q !== '',    fn ($qq) => $qq->where('venues.name', 'ILIKE', '%' . $q . '%'))
            ->when($kind !== '', fn ($qq) => $qq->where('venues.kind', $kind))
            ->orderByRaw('events_count DESC NULLS LAST')
            ->orderBy('venues.name');

        $page = $query->paginate($perPage);

        $this->attachUpcoming($page->items());

        return response()->json([
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
            'data' => WebVenueResource::collection($page->items())->toArray($request),
        ]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $venue = $this->baseQuery()
            ->where('venues.id', $id)
            ->first();

        if ($venue === null) {
            return response()->json(['error' => 'venue_not_found'], 404);
        }

        $venue->load('city:id,name,slug');
        $venue->setAttribute('genre_profile', $this->genreProfile((int) $venue->id));

        return response()->json([
            'data' => (new WebVenueDetailResource($venue))->toArray($request),
        ]);
    }

    /**
     * Календарь площадки: карта «YYYY-MM-DD» → число событий в этот день.
     * Фронт рисует месяц-сетку и листает месяцы клиентом без новых запросов,
     * поэтому отдаём всю карту разом (компактно даже для сотен событий).
     *
     * ВАЖНО — окно то же, что у /web/events (paginateUpcoming): от
     * `now - PAST_LOOKBACK_DAYS` и в будущее. Иначе календарь подсветил бы
     * старые дни, а клик по ним вернул бы пусто (лента режет прошлое окном).
     * Полная история старше недели — отдельная фича «Здесь уже проходило»
     * (нужен venue-эндпоинт без lookback + гидрация карточек).
     *
     * День = start_date (МСК-дата, как в афише/next_event); если её нет —
     * дата из start_time в МСК. Видимость — Event::visibleWeb(). Честный гейт
     * «мало событий → не показывать» решает фронт по сумме карты.
     */
    public function calendar(int $id, Request $request): JsonResponse
    {
        $venue = Venue::query()->active()->whereKey($id)->first(['id']);
        if ($venue === null) {
            return response()->json(['error' => 'venue_not_found'], 404);
        }

        // то же окно, что у публичной ленты — держим в sync через константу репо
        $nowMsk      = now('Europe/Moscow');
        $cutoffTs    = $nowMsk->copy()->subDays(\App\Repositories\EventRepository::PAST_LOOKBACK_DAYS);
        $fromDateMsk = $cutoffTs->toDateString();

        $dayExpr = "COALESCE(events.start_date, (events.start_time AT TIME ZONE 'Europe/Moscow')::date)";

        $rows = Event::query()
            ->visibleWeb()
            ->where('events.venue_id', $id)
            ->where(function ($w) use ($cutoffTs, $fromDateMsk) {
                $w->where('events.start_time', '>=', $cutoffTs)
                    ->orWhere(function ($x) use ($fromDateMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDateMsk);
                    });
            })
            ->whereRaw("$dayExpr IS NOT NULL")
            ->selectRaw("to_char($dayExpr, 'YYYY-MM-DD') as day")
            ->selectRaw('COUNT(*) as cnt')
            ->groupByRaw($dayExpr)
            ->get();

        $map = [];
        foreach ($rows as $r) {
            if ($r->day === null) {
                continue;
            }
            $map[(string) $r->day] = (int) $r->cnt;
        }

        return response()->json(['data' => $map]);
    }

    /**
     * «Здесь уже проходило» — лента ПРОШЕДШИХ событий площадки, all-time (в обход
     * lookback-окна ленты). Конверт идентичен /web/events (data + meta) → фронт
     * переиспользует dtoToEvent без изменений. Карточки прошлого приходят с
     * is_past=true → фронт приглушает их автоматически. Гейт пустого блока —
     * meta.total===0 (тот же пагинатор, что data; отдельный запрос не нужен).
     */
    public function pastEvents(int $id, Request $request): JsonResponse
    {
        $venue = Venue::query()->active()->whereKey($id)->first(['id']);
        if ($venue === null) {
            return response()->json(['error' => 'venue_not_found'], 404);
        }

        $perPage = max(1, min((int) $request->input('per_page', 24), 24));
        $page    = max(1, (int) $request->input('page', 1));

        ['page' => $paginator, 'totalEvents' => $total] = $this->events->listVenuePast($id, $perPage, $page);

        return response()->json([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => (int) $total,
                'last_page'    => $paginator->lastPage(),
            ],
            'data' => WebEventResource::collection($paginator->items()),
        ]);
    }

    /**
     * Профиль жанров площадки: топ interest-тегов по ВСЕЙ истории её событий
     * (прошедшие + будущие). «Здесь бывает» — идентичность места; работает,
     * даже когда афиша пуста, — единственный блок, который оживляет площадки
     * без предстоящих событий.
     *
     * Видимость — Event::visibleWeb(): тот же контракт, что каталог/лента (не
     * удалено + город active + не blacklist + дефолтная таксономия), чтобы
     * жанры совпадали с событиями, которые пользователь реально может открыть.
     * Даты НЕ фильтруем — профиль складывается из всей биографии площадки.
     *
     * Гейт против чипов-заглушек (аудит: у тонкой площадки один случайный тег
     * читается как «жанр»): нужно ≥3 тегированных события, и жанр либо
     * повторялся ≥3 раза, либо занимает ≥30% программы при ≥2 событиях. Иначе
     * профиля нет — честнее пустоты, чем ярлык из одного факта. Топ-5.
     *
     * @return array<int, array{slug: string, name: string, count: int}>
     */
    private function genreProfile(int $venueId): array
    {
        // знаменатель: сколько РАЗЛИЧНЫХ видимых событий площадки несут ≥1 тег
        $denom = (int) Event::query()
            ->visibleWeb()
            ->where('events.venue_id', $venueId)
            ->join('event_interest as ei', 'ei.event_id', '=', 'events.id')
            ->distinct()
            ->count('events.id');

        if ($denom < 3) {
            return [];
        }

        $rows = Event::query()
            ->visibleWeb()
            ->where('events.venue_id', $venueId)
            ->join('event_interest as ei', 'ei.event_id', '=', 'events.id')
            ->join('interests as i', 'i.id', '=', 'ei.interest_id')
            ->groupBy('i.slug', 'i.name')
            ->select('i.slug', 'i.name')
            ->selectRaw('COUNT(DISTINCT events.id) as cnt')
            ->orderByRaw('COUNT(DISTINCT events.id) DESC')
            ->orderBy('i.name')
            ->get();

        $chips = [];
        foreach ($rows as $r) {
            $cnt   = (int) $r->cnt;
            $share = $cnt / $denom;
            // жанр либо повторяется (≥3), либо доминирует (≥30% при ≥2 событиях)
            if ($cnt >= 3 || ($cnt >= 2 && $share >= 0.30)) {
                $chips[] = [
                    'slug'  => (string) $r->slug,
                    'name'  => (string) $r->name,
                    'count' => $cnt,
                ];
            }
            if (count($chips) >= 5) {
                break;
            }
        }

        return $chips;
    }

    public function map(Request $request): JsonResponse
    {
        $cityId = $this->resolveCityId($request);

        $rows = Venue::query()
            ->active()
            ->whereNotNull('location')
            // Не отдаём площадки-сироты без города: их координаты оказываются в чужих
            // городах (Казань/СПб) и «протекают» на карту, когда ?city не передан.
            ->whereNotNull('city_id')
            ->when($cityId !== null, fn ($qq) => $qq->where('city_id', $cityId))
            ->select('id', 'slug', 'name', 'kind', 'latitude', 'longitude')
            ->get();

        $features = [];
        foreach ($rows as $r) {
            if ($r->latitude === null || $r->longitude === null) continue;
            $features[] = [
                'type'       => 'Feature',
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $r->longitude, (float) $r->latitude],
                ],
                'properties' => [
                    'id'   => (int) $r->id,
                    'slug' => (string) $r->slug,
                    'name' => (string) $r->name,
                    'kind' => $r->kind !== null ? (string) $r->kind : null,
                ],
            ];
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Базовый Eloquent-query с city_slug, events_count и cover_image_url
     * подзапросами (один SQL, без N+1).
     */
    private function baseQuery()
    {
        $eventsCountSql = '(SELECT COUNT(*) FROM events e
            WHERE e.venue_id = venues.id AND e.deleted_at IS NULL)';

        // Первая картинка из event_sources.images первого (по дате) event'а
        // на этом venue. images — postgres `json` (не jsonb), используем
        // json_array_length + ->>0 для безопасного доступа.
        $coverSql = "(SELECT es.images->>0
            FROM events e
            JOIN event_sources es ON es.event_id = e.id
            WHERE e.venue_id = venues.id
              AND e.deleted_at IS NULL
              AND es.images IS NOT NULL
              AND json_array_length(es.images) > 0
            ORDER BY e.start_time ASC NULLS LAST
            LIMIT 1)";

        return Venue::query()
            ->active()
            ->leftJoin('cities as ct', 'ct.id', '=', 'venues.city_id')
            ->select([
                'venues.*',
                DB::raw('ct.slug as city_slug'),
                DB::raw($eventsCountSql . ' as events_count'),
                DB::raw($coverSql . ' as cover_image_url'),
            ]);
    }

    /**
     * next_event + upcoming_total для карточек каталога.
     *
     * «Предстоящее» = от полуночи СЕГОДНЯШНЕГО дня (МСК-дата, bare date в
     * сравнении — паритет с date_from паблик-ленты, который форсит полночь):
     * событие, начавшееся сегодня утром, остаётся «предстоящим» и даёт
     * карточке состояние «сегодня».
     *
     * Видимость — Event::visibleWeb(): тот же статус-скоуп, что выдача
     * /api/web/events (город active + не удалено + blacklist-гейт +
     * дефолтная таксономия ленты). Счётчик считает только реально видимые
     * события — НЕ архивный тотал (аудит 2026-07-10: архивные счётчики =
     * ложь пользователю).
     *
     * Батч: один window-запрос (COUNT/ROW_NUMBER OVER PARTITION BY venue_id)
     * на всю страницу (≤50 площадок), без N+1.
     *
     * @param array<int, Venue> $venues
     */
    private function attachUpcoming(array $venues): void
    {
        $ids = array_map(fn ($v) => (int) $v->id, $venues);
        if ($ids === []) {
            return;
        }

        $todayMsk = now('Europe/Moscow')->toDateString();

        $inner = Event::query()
            ->visibleWeb()
            ->whereIn('events.venue_id', $ids)
            ->where(function ($w) use ($todayMsk) {
                $w->where('events.start_time', '>=', $todayMsk)
                    ->orWhere(function ($x) use ($todayMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $todayMsk);
                    });
            })
            ->select([
                'events.venue_id',
                'events.id',
                'events.title',
                'events.start_time',
                'events.start_date',
                'events.time_precision',
            ])
            ->selectRaw('COUNT(*) OVER (PARTITION BY events.venue_id) AS upcoming_total')
            // хронология «ближайшего» — как в ленте: start_date, потом start_time
            ->selectRaw('ROW_NUMBER() OVER (
                PARTITION BY events.venue_id
                ORDER BY events.start_date ASC NULLS LAST, events.start_time ASC NULLS LAST, events.id ASC
            ) AS rn');

        $rows = DB::query()->fromSub($inner, 't')->where('t.rn', 1)->get();

        $byVenue = [];
        foreach ($rows as $r) {
            $startAt = null;
            if ($r->start_time !== null) {
                // как WebEventResource: инстант сохраняем, отдаём в МСК с offset
                $startAt = Carbon::parse($r->start_time)
                    ->setTimezone('Europe/Moscow')
                    ->toIso8601String();
            }

            $byVenue[(int) $r->venue_id] = [
                'total' => (int) $r->upcoming_total,
                'next'  => [
                    'id'             => (int) $r->id,
                    'title'          => (string) $r->title,
                    'start_at'       => $startAt,
                    'start_date'     => $r->start_date !== null ? substr((string) $r->start_date, 0, 10) : null,
                    'time_precision' => (string) ($r->time_precision ?? 'datetime'),
                ],
            ];
        }

        foreach ($venues as $v) {
            $hit = $byVenue[(int) $v->id] ?? null;
            $v->setAttribute('upcoming_total', $hit['total'] ?? 0);
            $v->setAttribute('next_event_payload', $hit['next'] ?? null);
        }
    }

    private function resolveCityId(Request $request): ?int
    {
        $cityIdInt = $request->input('city_id');
        if ($cityIdInt !== null && $cityIdInt !== '') {
            return (int) $cityIdInt;
        }

        $citySlug = trim((string) $request->input('city', ''));
        if ($citySlug !== '') {
            $id = DB::table('cities')->where('slug', $citySlug)->value('id');
            return $id ? (int) $id : null;
        }

        return null;
    }
}
