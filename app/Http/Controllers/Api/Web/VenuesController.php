<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Resources\WebEventResource;
use App\Http\Resources\WebVenueDetailResource;
use App\Http\Resources\WebVenueResource;
use App\Models\Event;
use App\Models\Venue;
use App\Repositories\EventRepository;
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
 *   GET /api/web/venues/{id}        — детальная карточка + future events;
 *   GET /api/web/venues/{id}/nearby — соседние площадки по расстоянию.
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
    /**
     * Предикат «предстоящего» события — буква в букву тот же, что в
     * attachUpcoming() и в паблик-ленте. Вынесен в константу, потому что
     * потребителей стало трое (ближайшее событие, ритм площадки, сортировка
     * соседей), а разъехавшаяся граница дала бы страницу, где в шапке висит
     * «ближайшее», а место помечено спящим.
     *
     * Про саму границу: сравнение timestamptz-колонки с голой датой ставит её
     * на полночь ТАЙМЗОНЫ СЕССИИ БД (у нас UTC), то есть на 03:00 МСК, а не на
     * московскую полночь. Это унаследовано от EventRepository, и чинить это
     * здесь нельзя: /web/events считает так же, а страница площадки, которая
     * спорит с лентой о том, что такое «сегодня», хуже, чем сдвинутая на три
     * часа граница. Ниже — оба плейсхолдера получают одну и ту же МСК-дату.
     */
    private const UPCOMING_SQL = '(events.start_time >= ?
        OR (events.start_time IS NULL AND events.start_date IS NOT NULL AND events.start_date >= ?))';

    /** Окно наблюдения за ритмом места и порог «спячки» — те же полгода. */
    private const RHYTHM_MONTHS = 6;

    /**
     * Порог правдоподобия для events_per_month. Два события за полгода — это
     * не ритм, а совпадение: «0 в месяц» после округления соврало бы про место,
     * где что-то всё-таки было, а «1 в месяц» — про регулярность, которой нет.
     */
    private const RHYTHM_MIN_EVENTS = 3;

    /** Меньше месяца наблюдений — делить на месяцы нечего. */
    private const RHYTHM_MIN_DAYS = 30;

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
        $this->loadSources($venue);
        $venue->setAttribute('genre_profile', $this->genreProfile((int) $venue->id));

        // Ближайшее событие берём тем же батчем, что и каталог: третья копия
        // хронологии «ближайшего» рано или поздно разъехалась бы с первыми
        // двумя, и страница места начала бы противоречить карточке в списке.
        $this->attachUpcoming([$venue]);
        $this->attachRhythm([$venue]);

        return response()->json([
            'data' => (new WebVenueDetailResource($venue))->toArray($request),
        ]);
    }

    /**
     * Соседние площадки: «раз это место не подошло — вот что рядом».
     *
     * Зачем фильтр «без единого события не показываем»: страница площадки, где
     * никогда ничего не проходило, — тупик для человека и малополезный контент
     * для поисковика. Гнать на неё трафик с соседнего блока значит своими
     * руками портить главный SEO-актив проекта. Гарантия — INNER JOIN с
     * агрегатом по видимым событиям: у кого нет ни одного, тот в выдачу
     * физически не попадает.
     *
     * Сортировка двухступенчатая: сперва те, у кого есть будущая афиша, потом
     * по расстоянию. Музей в 200 метрах без анонсов проигрывает клубу в 900 —
     * потому что в клуб можно пойти, а в музей пока только посмотреть.
     *
     * Радиус ограничен сверху 20 км: это соседи по городу, а не «все площадки
     * области»; без потолка запрос превращается в выгрузку каталога.
     */
    public function nearby(int $id, Request $request): JsonResponse
    {
        $venue = Venue::query()->active()->whereKey($id)->first(['id', 'city_id', 'latitude', 'longitude']);
        if ($venue === null) {
            return response()->json(['error' => 'venue_not_found'], 404);
        }

        $limit  = $this->intInput($request, 'limit', 6, 1, 12);
        $radius = $this->intInput($request, 'radius_m', 3000, 100, 20000);

        // Площадка без точки на карте (или без города) — соседей мерить не от
        // чего. Пустой список честнее, чем выдача «ближайших» от нуля координат.
        if ($venue->latitude === null || $venue->longitude === null || $venue->city_id === null) {
            return response()->json(['data' => []]);
        }

        $lon      = (float) $venue->longitude;
        $lat      = (float) $venue->latitude;
        $todayMsk = now('Europe/Moscow')->toDateString();

        // geography-каст даёт метры (у geometry-версии ST_DWithin радиус был бы
        // в градусах — на широте Воронежа это разъехалось бы почти вдвое между
        // осями). GIST-индекс на venues.location при этом не используется, но
        // выборка и так сужена городом: площадок в городе сотни, не миллионы.
        $point = 'ST_SetSRID(ST_Point(?, ?), 4326)::geography';

        // Отсекаем не только саму площадку по номеру, но и её близнеца по ТОЧКЕ.
        // Одно место заведено в каталоге несколькими записями: 13 записей стоят
        // на 6 точках. Без этого условия блок «афиша рядом» рекламировал площадке
        // её же саму под другим именем и с расстоянием 0 м — так выходило на 7
        // страницах. Порог метровый, а не в десятках метров: пар «разные площадки
        // ближе 25 м» в каталоге ровно одна, и резать её незачем.
        $geoAt = fn (int $r) => fn ($q) => $q
            ->where('venues.city_id', (int) $venue->city_id)
            ->where('venues.id', '<>', (int) $venue->id)
            ->whereNotNull('venues.location')
            ->whereRaw("ST_Distance(venues.location::geography, {$point}) > 1", [$lon, $lat])
            ->whereRaw("ST_DWithin(venues.location::geography, {$point}, ?)", [$lon, $lat, $r]);

        $fetch = function (int $r) use ($geoAt, $point, $lon, $lat, $todayMsk, $limit) {
            $geo = $geoAt($r);

            // Агрегат по событиям сужаем теми же соседями. Без этого группировка
            // пошла бы по всей таблице events ради полудюжины строк на выходе.
            $candidates = $geo(Venue::query()->active())->select('venues.id');

            $stats = Event::query()
                ->visibleWeb()
                ->whereIn('events.venue_id', $candidates)
                ->groupBy('events.venue_id')
                ->select('events.venue_id')
                ->selectRaw('BOOL_OR'.self::UPCOMING_SQL.' AS has_upcoming', [$todayMsk, $todayMsk]);

            return $geo($this->baseQuery())
                ->joinSub($stats, 'ev', 'ev.venue_id', '=', 'venues.id')
                ->selectRaw("ST_Distance(venues.location::geography, {$point}) AS distance_m", [$lon, $lat])
                ->selectRaw('ev.has_upcoming AS ev_has_upcoming')
                // NULLS LAST обязателен: у площадки, все события которой без дат,
                // BOOL_OR даёт NULL, а postgres при DESC поднимает NULL наверх —
                // и место без единой известной даты возглавило бы «рядом с вами».
                ->orderByRaw('ev.has_upcoming DESC NULLS LAST')
                ->orderByRaw('distance_m ASC')
                ->orderBy('venues.id')
                ->limit($limit)
                ->get();
        };

        $rows = $fetch($radius);

        // Второй проход по области. У площадки без своей афиши блок «афиша рядом»
        // и есть ответ страницы, а в трёх километрах соседа С ДАТАМИ не нашлось на
        // 9 пустых площадках из 65. Двадцать километров дают дату пятерым из девяти;
        // оставшимся четверым не помогает ничто, и они честно остаются пустыми.
        // Расстояние человек видит в строке, так что «рядом» себя не выдаёт за
        // соседний квартал. Явный radius_m из запроса не переопределяем.
        if (! $request->has('radius_m') && $rows->every(fn ($v) => ! $v->ev_has_upcoming)) {
            $wide = $fetch(20000);
            if ($wide->contains(fn ($v) => (bool) $v->ev_has_upcoming)) {
                $rows = $wide;
            }
        }

        // Служебный флаг сортировки наружу не отдаём.
        $rows->each(fn ($v) => $v->makeHidden(['ev_has_upcoming']));

        // Карточка соседа — та же, что в каталоге, значит и обогащение то же.
        $this->attachUpcoming($rows->all());

        return response()->json([
            'data' => WebVenueResource::collection($rows)->toArray($request),
        ]);
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
        // Считаем СОБЫТИЯ, а не строки. Структурные источники заводят строку
        // на каждый сеанс: у парка скалодромов «1000 Узлов» их 149 при одном
        // событии, у квест-комнаты 754 при пяти квестах. По сырому COUNT(*)
        // каталог возглавляли аттракционы, а концертные залы с настоящей
        // афишей падали вниз — и на проспекте Революции, 56 адрес ночного
        // клуба читался как парк.
        // Единицей события в проекте служит event_group_id; событию без
        // группы (их единицы) подставляем собственный id, иначе COUNT DISTINCT
        // проглотил бы все NULL разом.
        $eventsCountSql = "(SELECT COUNT(DISTINCT COALESCE(e.event_group_id::text, 'e' || e.id))
            FROM events e
            WHERE e.venue_id = venues.id AND e.deleted_at IS NULL)";

        // Картинка места. Раньше здесь стоял ORDER BY start_time ASC БЕЗ фильтра по
        // дате, то есть бралось самое старое событие за всю историю площадки: на 83
        // страницах из 108 в превью ссылки и в разметке висел постер уже прошедшего.
        // Теперь берём ближайшее БУДУЩЕЕ. День события считаем тем же выражением, что
        // и ритм площадки: у части событий времени нет, есть только дата.
        // images — postgres `json` (не jsonb), поэтому json_array_length и ->>0.
        $dayExpr = "COALESCE(e.start_date, (e.start_time AT TIME ZONE 'Europe/Moscow')::date)";

        $coverEventSql = "(SELECT es.images->>0
            FROM events e
            JOIN event_sources es ON es.event_id = e.id
            WHERE e.venue_id = venues.id
              AND e.deleted_at IS NULL
              AND es.images IS NOT NULL
              AND json_array_length(es.images) > 0
              AND {$dayExpr} >= ?::date
            ORDER BY {$dayExpr} ASC
            LIMIT 1)";

        // Запасной путь — обложка привязанного сообщества. Это широкий баннер паблика
        // (1920×768), а не фотография места, поэтому в карточки каталога и рельса он
        // НЕ идёт: там слот 3:4, и от баннера со сплошным текстом остался бы обрезок.
        // Его назначение — превью ссылки, где 1200×630 почти впору. Куда его пускать,
        // решает ресурс по cover_source, а не этот запрос.
        $coverCommunitySql = "(SELECT c.image_url
            FROM communities c
            WHERE c.venue_id = venues.id
              AND c.deleted_at IS NULL
              AND COALESCE(c.image_url, '') <> ''
            ORDER BY c.id ASC
            LIMIT 1)";

        return Venue::query()
            ->active()
            ->leftJoin('cities as ct', 'ct.id', '=', 'venues.city_id')
            ->select([
                'venues.*',
                DB::raw('ct.slug as city_slug'),
                DB::raw($eventsCountSql . ' as events_count'),
                DB::raw($coverCommunitySql . ' as cover_community_url'),
            ])
            ->selectRaw($coverEventSql . ' as cover_event_url', [now('Europe/Moscow')->toDateString()]);
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

        // Один фильтр на оба запроса — «ближайшее» и «всего предстоящих»
        // обязаны описывать одно и то же множество, иначе карточка начнёт
        // противоречить сама себе.
        $upcoming = fn () => Event::query()
            ->visibleWeb()
            ->whereIn('events.venue_id', $ids)
            ->where(function ($w) use ($todayMsk) {
                $w->where('events.start_time', '>=', $todayMsk)
                    ->orWhere(function ($x) use ($todayMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $todayMsk);
                    });
            });

        // Отдельным запросом, потому что COUNT(DISTINCT …) OVER (…) postgres
        // не умеет. Считаем события, а не сеансы: см. events_count в
        // baseQuery() — там та же причина.
        $totals = [];
        $totalRows = $upcoming()
            ->groupBy('events.venue_id')
            ->select('events.venue_id')
            ->selectRaw("COUNT(DISTINCT COALESCE(events.event_group_id::text, 'e' || events.id)) AS distinct_total")
            ->get();

        foreach ($totalRows as $row) {
            $totals[(int) $row->venue_id] = (int) $row->distinct_total;
        }

        $inner = $upcoming()
            ->select([
                'events.venue_id',
                'events.id',
                'events.title',
                'events.start_time',
                'events.start_date',
                'events.time_precision',
            ])
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
                'total' => (int) ($totals[(int) $r->venue_id] ?? 0),
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

    /**
     * Сообщества, связанные с площадкой, — для строки-атрибуции на её странице.
     *
     * kudab — агрегатор чужих постов, и назвать источник для него обязанность,
     * а не украшение: на карточке события атрибуция есть (EventSourceLine), на
     * странице места её не было вовсе, хотя половина площадок родилась именно
     * из сообществ. Заодно это первые внешние ссылки на страницах площадок —
     * сегодня уходить с них некуда.
     *
     * Связь берём ТОЛЬКО через FK communities.venue_id. В source_meta у части
     * площадок лежит from_community_id, но это трассировка происхождения — след
     * того, кто площадку породил, а не утверждение «этот источник ведёт это
     * место»: сообщество могли отвязать, слить или переназначить, а след
     * останется прежним.
     *
     * ЧТО ИМЕННО УТВЕРЖДАЕТ ЭТОТ FK — важно не преувеличить. Его ставит парсер,
     * когда заводит площадку по HQ-адресу сообщества: это «аккаунт места», а не
     * доказанный поставщик его афиши. Провенанс конкретного события лежит в
     * events.community_id и совпадает не всегда: из 51 площадки со связью у 32
     * афиша действительно приходит из этого сообщества, у 4 — только из чужих
     * (Я.Афиша, Qtickets, сторонние организаторы), у 15 событий нет вовсе.
     * Поэтому блок называет сообщество места, а не клянётся, что каждое событие
     * пришло отсюда; фронту подпись «Источник» стоит держать в этом же объёме.
     *
     * Гейт качества ссылок обязателен. status='active' отсекает чёрный список,
     * last_is_active IS DISTINCT FROM false — проверенно мёртвые ссылки
     * (ночной верификатор их уже пометил). NULL пропускаем: «ещё не проверяли»
     * — не то же самое, что «не работает». По всей таблице гейт снимает 3
     * чёрных и 13 мёртвых ссылки из 128; на страницах площадок сегодня режет
     * ровно одну — у ВГУ (id 14), и площадка остаётся с названным источником
     * без кликабельной ссылки. Так и надо: ссылка в никуда хуже её отсутствия,
     * а умолчать про источник нельзя.
     *
     * Всё грузится eager-load'ом (3 запроса на любое число сообществ), потому
     * что блок обязан пережить переезд в каталог: там площадок до полусотни на
     * страницу, и запрос-на-площадку превратил бы список в сотню round-trip'ов.
     */
    private function loadSources(Venue $venue): void
    {
        $venue->load([
            'communities' => function ($q) {
                $q->select('communities.id', 'communities.venue_id', 'communities.name', 'communities.avatar_url')
                    // Свежесть — по самому свежему прочитанному посту, ВКЛЮЧАЯ
                    // мягко удалённые. context:cleanup гасит посты старше 30
                    // дней, которые не породили ни одного события: это уборка
                    // хранилища, а не отзыв факта — пост мы прочитали, просто
                    // не храним его текст. С фильтром deleted_at IS NULL
                    // свежесть «ТЕАТР. АКТ» съезжала с декабря 2025 на август
                    // 2024 — на полтора года мимо того, что мы правда читали.
                    ->withMax('contextPosts as last_post_at', 'published_at')
                    ->orderBy('communities.id');
            },
            'communities.socialLinks' => function ($q) {
                $q->select(
                    'community_social_links.id',
                    'community_social_links.community_id',
                    'community_social_links.social_network_id',
                    'community_social_links.url',
                )
                    ->where('community_social_links.status', 'active')
                    ->whereRaw('community_social_links.last_is_active IS DISTINCT FROM false')
                    ->orderBy('community_social_links.id');
            },
            'communities.socialLinks.socialNetwork:id,slug,name',
        ]);
    }

    /**
     * Ритм места: как часто тут что-то происходит, когда было в последний раз
     * и не заброшено ли оно. Считается по истории событий — единственному
     * факту, который у площадки есть всегда (описание и контакты есть далеко
     * не у всех).
     *
     * Всё одним группировочным запросом на весь список площадок: блок нужен и
     * на странице места, и потенциально в каталоге, а запрос-на-площадку
     * превратил бы каталог в полсотни round-trip'ов.
     *
     * День события — та же МСК-дата, что в календаре: start_date, а при её
     * отсутствии дата из start_time в МСК. Единица счёта — event_group_id (см.
     * events_count в baseQuery): у квест-комнаты 754 строки на пять квестов, и
     * без группировки «ритм» такого места был бы 125 событий в месяц.
     *
     * ПРОШЛОЕ И БУДУЩЕЕ ЗДЕСЬ ЖИВУТ ПО РАЗНЫМ ПРАВИЛАМ — намеренно, не чините.
     *
     * Прошлое (last_event_at, events_per_month, past_total) считается предикатом
     * EventRepository::pastExpression() — тем самым, по которому /past-events
     * набирает блок «Здесь уже проходило». Человек видит этот блок на той же странице, и
     * ритм обязан говорить о том, что человеку показано. Пока правила
     * расходились, Музей Бунина (venue 24) писал «здесь давно тихо», а блок
     * ниже показывал события от 16 июля.
     *
     * Будущее (has_upcoming, а значит is_dormant) считается предикатом
     * UPCOMING_SQL — тем, по которому живут каталог и лента, чтобы «ближайшее» в
     * шапке и «спит» в ритме не спорили друг с другом.
     *
     * Между двумя правилами есть щель — событие сегодня до 03:00 МСК не
     * предстоящее и ещё не прошедшее (grace-час у pastExpression(), полночь
     * сессии БД у UPCOMING_SQL). Это осознанный размен: щель шириной в три ночных часа
     * дешевле, чем блок, который спорит с соседним блоком той же страницы.
     * Единственное поле, которому щель была опасна, — is_dormant (флаг
     * переворачивался), и оно считается по any_day, а не по last_event_at.
     *
     * Щель покрыта тестом: «сейчас» приходит в предикат прошлого связанным
     * параметром из PHP (EventRepository::pastExpression()), поэтому
     * Carbon::setTestNow() двигает обе границы разом и ночь 01:30 в тесте
     * воспроизводится обычным способом.
     *
     * Отдельно про NULL: предикат прошлого пишется явными ветками IS NULL /
     * IS NOT NULL, а не отрицанием предстоящего. У события без start_time
     * сравнение `start_time >= …` даёт NULL, `NOT NULL` — тоже NULL, и строка
     * молча выпадает из FILTER. Ровно на этом ритм и терял 231 событие на 33
     * площадках: у Музея Бунина все события заведены одной датой без времени.
     *
     * @param array<int, Venue> $venues
     */
    private function attachRhythm(array $venues): void
    {
        $ids = array_map(fn ($v) => (int) $v->id, $venues);
        if ($ids === []) {
            return;
        }

        $nowMsk      = now('Europe/Moscow');
        $todayMsk    = $nowMsk->toDateString();
        $windowStart = $nowMsk->copy()->subMonths(self::RHYTHM_MONTHS)->toDateString();

        $dayExpr = "COALESCE(events.start_date, (events.start_time AT TIME ZONE 'Europe/Moscow')::date)";
        $unit    = "COALESCE(events.event_group_id::text, 'e' || events.id)";
        // предикат прошлого и его связанные значения времени — общие с
        // /past-events, включая «сейчас»: см. EventRepository::pastExpression()
        [$past, $pastAt] = EventRepository::pastExpression();

        $rows = Event::query()
            ->visibleWeb()
            ->whereIn('events.venue_id', $ids)
            ->groupBy('events.venue_id')
            ->select('events.venue_id')
            ->selectRaw(
                "COUNT(DISTINCT {$unit}) FILTER (WHERE {$dayExpr} >= ?::date AND {$past}) AS window_events",
                [$windowStart, ...$pastAt],
            )
            ->selectRaw("COUNT(DISTINCT {$unit}) FILTER (WHERE {$past}) AS past_total", $pastAt)
            ->selectRaw("MIN({$dayExpr}) FILTER (WHERE {$past}) AS first_day", $pastAt)
            ->selectRaw("MAX({$dayExpr}) FILTER (WHERE {$past}) AS last_day", $pastAt)
            // Без FILTER, по ВСЕМ событиям: самая свежая дата, которая про это
            // место вообще известна. Нужна только «спячке» — см. ниже, почему
            // last_day для неё не годится.
            ->selectRaw("MAX({$dayExpr}) AS any_day")
            ->selectRaw('BOOL_OR'.self::UPCOMING_SQL.' AS has_upcoming', [$todayMsk, $todayMsk])
            ->get();

        $byVenue = [];
        $pastTotals = [];
        foreach ($rows as $r) {
            $firstDay = $r->first_day !== null ? substr((string) $r->first_day, 0, 10) : null;
            $lastDay  = $r->last_day !== null ? substr((string) $r->last_day, 0, 10) : null;
            $anyDay   = $r->any_day !== null ? substr((string) $r->any_day, 0, 10) : null;

            $pastTotals[(int) $r->venue_id] = (int) $r->past_total;
            $byVenue[(int) $r->venue_id] = [
                'events_per_month' => $this->eventsPerMonth((int) $r->window_events, $firstDay, $windowStart, $todayMsk),
                'last_event_at'    => $lastDay,
                // «Спит» — это отсутствие будущего плюс отсутствие любой
                // известной даты за полгода. Место, которое молчало полгода и
                // вчера объявило концерт, спящим называть нельзя: как раз оно
                // и вернулось.
                //
                // Считаем по any_day, а НЕ по last_event_at, из-за щели между
                // двумя предикатами (см. докблок метода): событие сегодня в
                // 01:30 МСК ещё не прошедшее и уже не предстоящее, и по
                // last_event_at клуб получал бы «здесь давно тихо» за полчаса
                // до собственного концерта. Дата известна — значит не спит.
                'is_dormant'       => ! (bool) $r->has_upcoming && ($anyDay === null || $anyDay < $windowStart),
            ];
        }

        foreach ($venues as $v) {
            // Ни одного видимого события — ритма нет вовсе (null), а не «ноль в
            // месяц»: про такое место мы просто ничего не знаем, и врать нулём
            // на странице, которая и так пустая, нельзя.
            $v->setAttribute('rhythm', $byVenue[(int) $v->id] ?? null);
            // А вот past_total — всегда число: это серверный гейт блока «Здесь
            // уже проходило», и «не знаю» фронту тут бесполезно. Ноль значит
            // ноль: блок не рисуем.
            $v->setAttribute('past_total', $pastTotals[(int) $v->id] ?? 0);
        }
    }

    /**
     * Среднее число событий в месяц. Делим не на шесть месяцев вслепую, а на
     * фактически наблюдаемый отрезок: площадка, попавшая в базу пять недель
     * назад, при делении на 6 выглядела бы впятеро тише, чем есть.
     */
    private function eventsPerMonth(int $windowEvents, ?string $firstDay, string $windowStart, string $today): ?int
    {
        if ($windowEvents < self::RHYTHM_MIN_EVENTS) {
            return null;
        }

        $from = ($firstDay !== null && $firstDay > $windowStart) ? $firstDay : $windowStart;
        // round, а не приведение к int: diffInDays у Carbon 3 возвращает float,
        // и ровно 175 суток пришли бы как 174.99… при любом дрейфе часового пояса
        $days = (int) round(Carbon::parse($from)->diffInDays(Carbon::parse($today)));

        if ($days < self::RHYTHM_MIN_DAYS) {
            return null;
        }

        // 30.44 — средняя длина месяца в году; на полугодовом окне разница с
        // «30» набегает в пятую часть месяца, а число на странице целое.
        $months = min((float) self::RHYTHM_MONTHS, $days / 30.44);

        return max(1, (int) round($windowEvents / $months));
    }

    /** Целочисленный query-параметр с дефолтом и потолком: мусор → дефолт. */
    private function intInput(Request $request, string $key, int $default, int $min, int $max): int
    {
        $raw   = $request->input($key);
        $value = is_numeric($raw) ? (int) $raw : $default;

        return max($min, min($value, $max));
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
