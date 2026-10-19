<?php

namespace App\Http\Controllers\Api\Web;

use Illuminate\Support\Arr;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebEventResource;
use App\Models\City;
use App\Models\Event;
use App\Services\EventService;
use App\Services\EventSurvivor;
use App\Services\Telegram\Scoring\EventAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Support\KeyboardLayout;
use App\Support\SearchLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\WebEventDetailResource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class EventsController extends Controller
{
    /** Свежий ответ ленты; дальше до `CACHE_STALE_SECONDS` отдаём прежний и пересчитываем после ответа. */
    private const CACHE_FRESH_SECONDS = 60;

    private const CACHE_STALE_SECONDS = 300;

    public function __construct(
        private readonly EventService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'page'         => ['sometimes','integer','min:1'],
            'per_page'     => ['sometimes','integer','min:1'],

            'city'         => ['sometimes','string','max:64','regex:/^[a-z0-9-]+$/'],

            'date_from'    => ['sometimes','date'],
            'date_to'      => ['sometimes','date'],

            // Отбор по времени ПОПАДАНИЯ события к нам, а не по времени самого
            // события: «сколько анонсов добавилось за сутки». Отдельно от date_from,
            // потому что вопросы разные и в одном запросе могут стоять оба.
            'created_from' => ['sometimes','date'],

            'when'         => ['sometimes', Rule::in(['today','now','weekend'])],
            'free'         => ['sometimes','boolean'],

            'grouped'      => ['sometimes','boolean'],
            'grouped_by_post' => ['sometimes','boolean'],

            'kids'         => ['sometimes','boolean'],
            'include_all'  => ['sometimes','boolean'],

            'q'            => ['sometimes','string','max:255'],
            'community_id' => ['sometimes','integer'],
            'venue_id'     => ['sometimes','integer','min:1'],
            'interests'    => ['sometimes','array'],
            // Double-write для Этапа 2 rollout: legacy фронт шлёт int (ID),
            // новый — slug. Принимаем оба, но не mixed (см. after-callback).
            // После миграции фронта закрытым PR убрать legacy int-ветку.
            'interests.*'  => ['required', $this->interestItemRule()],

            // выбор колеса «Чего хочется?», см. paginateUpcomingWeb
            'prefer'       => ['sometimes','array'],
            'prefer.*'     => ['required','string','max:64','regex:/^[a-z0-9-]+$/'],

            'priced'       => ['sometimes','boolean'],
            'price_min'    => ['sometimes','integer','min:0'],
            'price_max'    => ['sometimes','integer','min:0'],
            'tod'          => ['sometimes', Rule::in(['morning','day','evening','night'])],
            'entry'        => ['sometimes', Rule::in(EventAccess::ENTRIES)],

            'sort'         => ['sometimes', Rule::in(['start_at','start_date','start_time','created_at','price_min','top'])],
            'dir'          => ['sometimes', Rule::in(['asc','desc'])],
        ]);
        $validator->after(fn ($v) => $this->rejectMixedInterests($request, $v));
        $v = $validator->validate();

        // date_from без date_to сайт шлёт всегда («не показывать начавшееся»), это не фильтр
        $searchFilters = Arr::except($v, ['q', 'city', 'page', 'per_page', 'grouped', 'grouped_by_post', 'date_from', 'sort', 'dir']);

        $pageNum = (int) ($v['page'] ?? 1);

        $perPage = (int) ($v['per_page'] ?? 20);
        $perPage = max(1, min($perPage, 50));

        unset($v['page'], $v['per_page']);

        if (isset($v['price_min'], $v['price_max'])) {
            $a = (int) $v['price_min'];
            $b = (int) $v['price_max'];
            if ($a > $b) {
                $v['price_min'] = $b;
                $v['price_max'] = $a;
            }
        }

        // Сайт шлёт «не начавшееся» текущим моментом до секунды. Минута точности хватает,
        // зато одинаковые запросы этой минуты получают один ответ из кэша.
        if (isset($v['date_from'])) {
            $v['date_from'] = preg_replace('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}):\d{2}(\.\d+)?$/', '$1:00', (string) $v['date_from']);
        }

        if (!empty($v['when']) && empty($v['date_from']) && empty($v['date_to'])) {
            $now = Carbon::now('Europe/Moscow')->startOfMinute();

            if ($v['when'] === 'today') {
                $v['date_from'] = $now->copy()->startOfDay()->toDateTimeString();
                $v['date_to']   = $now->copy()->endOfDay()->toDateTimeString();
            } elseif ($v['when'] === 'weekend') {
                [$v['date_from'], $v['date_to']] = $this->weekendWindow($now);
            } elseif ($v['when'] === 'now') {
                $v['date_from'] = $now->copy()->subHours(2)->toDateTimeString();
                $v['date_to']   = $now->copy()->addHours(4)->toDateTimeString();
            }
        }

        unset($v['when']);

        if (array_key_exists('q', $v)) {
            $v['q'] = trim((string)$v['q']);
        }

        // city=slug -> city_id
        $citySlug = trim((string)($v['city'] ?? ''));
        if ($citySlug !== '') {
            $cityId = City::query()
                ->where('slug', $citySlug)
                ->where('status', 'active')
                ->value('id');

            if (!$cityId) {
                return response()->json([
                    'meta' => [
                        'current_page' => $pageNum,
                        'per_page'     => $perPage,
                        'total'        => 0,
                        'last_page'    => 1,
                    ],
                    'data' => [],
                ]);
            }

            $v['city_id'] = (int) $cityId;
            unset($v['city']);
        }

        // поиск идёт мимо кэша: разных слов слишком много, и каждый запрос пишется в журнал поиска
        if (($v['q'] ?? '') !== '') {
            return $this->listResponse($request, $v, $perPage, $pageNum, $searchFilters);
        }

        ksort($v);
        $json = Cache::flexible(
            'web:events:'.md5(json_encode([$v, $perPage, $pageNum])),
            [self::CACHE_FRESH_SECONDS, self::CACHE_STALE_SECONDS],
            // сжимаем, чтобы кэш занимал меньше; base64 нужен хранилищу кэша в базе, двоичное оно не примет
            fn () => base64_encode(gzcompress($this->listResponse($request, $v, $perPage, $pageNum, $searchFilters)->getContent())),
            ['seconds' => 30],
        );

        return new JsonResponse(gzuncompress(base64_decode($json)), 200, [], 0, true);
    }

    private function listResponse(Request $request, array $v, int $perPage, int $pageNum, array $searchFilters): JsonResponse
    {
        $result = $this->service->listWeb($v, $perPage);
        // ничего не нашлось, а запрос набран латиницей: пробуем его же в русской раскладке
        $corrected = null;
        if ($result['page']->total() === 0 && ($fixed = KeyboardLayout::toRussian($v['q'] ?? '')) !== null) {
            $retry = $this->service->listWeb(['q' => $fixed] + $v, $perPage);
            if ($retry['page']->total() > 0) {
                [$result, $corrected] = [$retry, $fixed];
            }
        }
        $page = $result['page'];
        $totalEvents = $result['totalEvents'];

        $meta = [
            'current_page' => $page->currentPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
            'last_page'    => $page->lastPage(),
        ];

        // total_events: реальное количество events до схлопывания grouped /
        // grouped_by_post. Frontend использует это для display-счётчика, а
        // meta.total — для hasMore-логики (число rep-карточек).
        if ($totalEvents !== null) {
            $meta['total_events'] = $totalEvents;
        }
        // сколько из total_events подходит под выбор колеса
        if (($result['preferredTotal'] ?? null) !== null) {
            $meta['preferred_total'] = $result['preferredTotal'];
        }
        // тема запроса и сколько событий добрано только по ней
        if (($result['topic'] ?? null) !== null) {
            $meta['topic'] = $result['topic'];
            $searchFilters['topic_total'] = $result['topic']['total'];
        }
        // вид места из запроса («парк») и сколько событий в местах этого вида
        if (($result['place'] ?? null) !== null) {
            $meta['place'] = $result['place'];
            $searchFilters['place_total'] = $result['place']['total'];
        }
        // сайт подставляет это слово в поле поиска
        if ($corrected !== null) {
            $meta['corrected_q'] = $corrected;
            $searchFilters['corrected_q'] = $corrected;
        }

        // Следующие страницы и число на кнопке фильтров (per_page=1, `FiltersOverlay`) повторяют
        // уже записанный поиск.
        if (($v['q'] ?? '') !== '' && $pageNum === 1 && $perPage > 1) {
            SearchLog::record($request, $v['q'], $v['city_id'] ?? null, $totalEvents ?? $page->total(), $searchFilters);
        }

        return response()->json([
            'meta' => $meta,
            'data' => WebEventResource::collection($page->items()),
        ]);
    }

    /**
     * GET /api/web/events/past — что недавно прошло по слову, для пустого поиска на сайте.
     */
    public function past(Request $request): JsonResponse
    {
        $v = $request->validate([
            'city' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'q' => ['required', 'string', 'max:255'],
        ]);

        $filters = ['q' => trim($v['q'])];
        if (! empty($v['city'])) {
            $cityId = City::query()->where('slug', $v['city'])->where('status', 'active')->value('id');
            if (! $cityId) {
                return response()->json(['data' => []]);
            }
            $filters['city_id'] = (int) $cityId;
        }

        return response()->json([
            'data' => WebEventResource::collection($this->service->listSearchPast($filters)),
        ]);
    }

    /**
     * GET /api/web/events/map
     * Точки событий для карты города (/map): GeoJSON FeatureCollection, отбор по городу
     * и окну даты, БЕЗ пагинации (все точки окна разом). Отдельная ручка, потому что
     * /events режет per_page=50 — карте нужны все точки сразу (по образцу venues/map).
     * Каждое событие = одна точка (без grouped-схлопывания). properties — превью для карточки.
     */
    public function map(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'city'         => ['sometimes','string','max:64','regex:/^[a-z0-9-]+$/'],
            'date_from'    => ['sometimes','date'],
            'date_to'      => ['sometimes','date'],
            'when'         => ['sometimes', Rule::in(['today','now','weekend'])],
            'free'         => ['sometimes','boolean'],
            'kids'         => ['sometimes','boolean'],
            'q'            => ['sometimes','string','max:255'],
            'venue_id'     => ['sometimes','integer','min:1'],
            'interests'    => ['sometimes','array'],
            'interests.*'  => ['required', $this->interestItemRule()],
            'priced'       => ['sometimes','boolean'],
            'price_min'    => ['sometimes','integer','min:0'],
            'price_max'    => ['sometimes','integer','min:0'],
            'tod'          => ['sometimes', Rule::in(['morning','day','evening','night'])],
            'entry'        => ['sometimes', Rule::in(EventAccess::ENTRIES)],
        ]);
        $validator->after(fn ($v) => $this->rejectMixedInterests($request, $v));
        $v = $validator->validate();

        if (isset($v['price_min'], $v['price_max'])) {
            $a = (int) $v['price_min'];
            $b = (int) $v['price_max'];
            if ($a > $b) {
                $v['price_min'] = $b;
                $v['price_max'] = $a;
            }
        }

        // when -> date_from/date_to (идентично index)
        if (!empty($v['when']) && empty($v['date_from']) && empty($v['date_to'])) {
            $now = Carbon::now('Europe/Moscow');
            if ($v['when'] === 'today') {
                $v['date_from'] = $now->copy()->startOfDay()->toDateTimeString();
                $v['date_to']   = $now->copy()->endOfDay()->toDateTimeString();
            } elseif ($v['when'] === 'weekend') {
                [$v['date_from'], $v['date_to']] = $this->weekendWindow($now);
            } elseif ($v['when'] === 'now') {
                $v['date_from'] = $now->copy()->subHours(2)->toDateTimeString();
                $v['date_to']   = $now->copy()->addHours(4)->toDateTimeString();
            }
        }
        unset($v['when']);

        if (array_key_exists('q', $v)) {
            $v['q'] = trim((string) $v['q']);
        }

        // Защита от «отдай всё»: без окна даты карта грузила бы все предстоящие события
        // разом. По умолчанию — «эта неделя» (сегодня..+7д, МСК). Явный when/date всё равно
        // выигрывает (этот блок срабатывает, только если окна не задали вовсе).
        if (empty($v['date_from']) && empty($v['date_to'])) {
            $now = Carbon::now('Europe/Moscow');
            $v['date_from'] = $now->copy()->startOfDay()->toDateTimeString();
            $v['date_to']   = $now->copy()->addDays(7)->endOfDay()->toDateTimeString();
        }

        // Город обязателен: без него карта не отдаёт точки (паритет с venues/map —
        // отбор по городу защищает от событий-сирот в чужих городах).
        $citySlug = trim((string) ($v['city'] ?? ''));
        if ($citySlug === '') {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }
        $cityId = City::query()
            ->where('slug', $citySlug)
            ->where('status', 'active')
            ->value('id');
        if (!$cityId) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }
        $v['city_id'] = (int) $cityId;
        unset($v['city']);

        // Все точки окна разом: большой per_page (карта не пагинируется, ей нужны все точки).
        // NB: 5000 — предохранитель; считает СЫРЫЕ строки (серии не схлопнуты здесь, а на фронте).
        $result = $this->service->listWeb($v, 5000);
        $items = $result['page']->items();

        // Federation-ключ группы: событие, кросс-запощенное в N пабликов, имеет РАЗНЫЕ
        // event_group_id, но общий federation_id → на карте это ОДНА точка (как в ленте /events,
        // где read-путь схлопывает по COALESCE(federation_id, event_group_id)). Один доп-запрос.
        $groupIds = [];
        foreach ($items as $e) {
            $g = (int) ($e->event_group_id ?? 0);
            if ($g > 0) {
                $groupIds[$g] = true;
            }
        }
        $fedByGroup = [];
        if ($groupIds !== []) {
            $fedByGroup = \Illuminate\Support\Facades\DB::table('event_groups')
                ->whereIn('id', array_keys($groupIds))
                ->whereNotNull('federation_id')
                ->pluck('federation_id', 'id')
                ->all();
        }

        // Темы точки: карта их не отдавала, и знак темы на метке фронту брать было неоткуда.
        // Отдаём слоги как есть (включая жанры-листья) — сведение к корню и выбор одной темы
        // из нескольких живут во фронте, где для этого есть карта алиасов.
        $eventIds = [];
        foreach ($items as $e) {
            $eventIds[] = (int) $e->id;
        }
        $interestsById = [];
        if ($eventIds !== []) {
            $rows = \Illuminate\Support\Facades\DB::table('event_interest')
                ->join('interests', 'interests.id', '=', 'event_interest.interest_id')
                ->whereIn('event_interest.event_id', $eventIds)
                ->get(['event_interest.event_id as eid', 'interests.slug as slug']);
            foreach ($rows as $r) {
                $interestsById[(int) $r->eid][] = (string) $r->slug;
            }
        }

        $features = [];
        foreach ($items as $e) {
            if ($e->latitude === null || $e->longitude === null) {
                continue;
            }

            // start_at в МСК с offset — как WebEventResource (фронт извлекает HH:MM из строки)
            $startAt = $e->start_time !== null
                ? Carbon::parse($e->start_time)->setTimezone('Europe/Moscow')->toIso8601String()
                : null;

            $free = ($e->price_status === 'free')
                || ((int) ($e->price_min ?? -1) === 0 && $e->price_max === null);

            // ключ группы для схлопывания на фронте: federation (кросс-пост) → 'f{id}', иначе серия → 'g{id}'
            $gid = (int) ($e->event_group_id ?? 0);
            $groupKey = null;
            if ($gid > 0) {
                $fed = $fedByGroup[$gid] ?? null;
                $groupKey = $fed ? ('f' . (int) $fed) : ('g' . $gid);
            }

            $features[] = [
                'type'       => 'Feature',
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $e->longitude, (float) $e->latitude],
                ],
                'properties' => [
                    'id'        => (int) $e->id,
                    'title'     => (string) ($e->title ?? ''),
                    'start_at'  => $startAt,
                    'poster'    => $e->poster !== null ? (string) $e->poster : null,
                    'price_min' => $e->price_min !== null ? (int) $e->price_min : null,
                    'free'      => $free,
                    'venue_id'  => $e->venue_id !== null ? (int) $e->venue_id : null,
                    // ключ группы: фронт схлопывает точки одной группы (серия ИЛИ кросс-пост) в одну
                    'group_key' => $groupKey,
                    'interests' => $interestsById[(int) $e->id] ?? [],
                ],
            ];
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    public function show(int $id, EventSurvivor $survivors): JsonResponse
    {
        try {
            $event = $this->service->getWeb($id);
        } catch (ModelNotFoundException $missing) {
            // ссылки на склеенное событие живут в постах канала и закладках: говорим, куда оно переехало
            $survivor = $survivors->find($id)[0] ?? $this->sameSeriesDay($id);
            if ($survivor !== null && $this->visibleOnWeb((int) $survivor->id)) {
                return response()->json(['message' => 'Событие переехало.', 'moved_to' => (int) $survivor->id], 404);
            }

            throw $missing;
        }

        return response()->json([
            'data' => (new WebEventDetailResource($event))->toArray(request()),
        ]);
    }

    /** Снятый день серии, например выходной музея, ведёт на ближайший живой день той же серии, сначала вперёд. */
    private function sameSeriesDay(int $id): ?Event
    {
        $dead = Event::onlyTrashed()->find($id);
        if ($dead?->event_group_id === null || $dead->start_date === null) {
            return null;
        }
        $day = $dead->start_date->toDateString();

        return Event::query()
            ->where('event_group_id', $dead->event_group_id)
            ->orderByRaw('start_date < ?::date', [$day])
            ->orderByRaw('abs(start_date - ?::date)', [$day])
            ->orderBy('id')
            ->first();
    }

    private function visibleOnWeb(int $id): bool
    {
        try {
            $this->service->getWeb($id);

            return true;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    /**
     * GET /api/web/events/random
     * Случайное событие под те же фильтры что /events, для «Удиви меня» на kudab-frontend.
     * Сеансы одного события считаются одним событием. `groups[]` — темы через запятую,
     * в ответе `meta.group` — номер группы, из которой взято событие.
     */
    public function random(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'city'         => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'date_from'    => ['sometimes', 'date'],
            'date_to'      => ['sometimes', 'date'],
            'when'         => ['sometimes', Rule::in(['today', 'now', 'weekend'])],
            'free'         => ['sometimes', 'boolean'],
            'kids'         => ['sometimes', 'boolean'],
            'include_all'  => ['sometimes', 'boolean'],
            'q'            => ['sometimes', 'string', 'max:255'],
            'community_id' => ['sometimes', 'integer'],
            'venue_id'     => ['sometimes', 'integer', 'min:1'],
            'interests'    => ['sometimes', 'array'],
            'interests.*'  => ['required', $this->interestItemRule()],
            'priced'       => ['sometimes', 'boolean'],
            'price_min'    => ['sometimes', 'integer', 'min:0'],
            'price_max'    => ['sometimes', 'integer', 'min:0'],
            'tod'          => ['sometimes', Rule::in(['morning', 'day', 'evening', 'night'])],
            'entry'        => ['sometimes', Rule::in(EventAccess::ENTRIES)],
            'exclude_ids'  => ['sometimes', 'array', 'max:50'],
            'exclude_ids.*' => ['integer'],
            'seen_ids'     => ['sometimes', 'array', 'max:50'],
            'seen_ids.*'   => ['integer'],
            'groups'       => ['sometimes', 'array', 'max:10'],
            'groups.*'     => ['string', 'max:255', 'regex:/^[a-z0-9-]+(,[a-z0-9-]+)*$/'],
            'widen'        => ['sometimes', 'boolean'],
        ]);
        $validator->after(fn ($v) => $this->rejectMixedInterests($request, $v));
        $v = $validator->validate();

        if (isset($v['price_min'], $v['price_max'])) {
            $a = (int) $v['price_min'];
            $b = (int) $v['price_max'];
            if ($a > $b) {
                $v['price_min'] = $b;
                $v['price_max'] = $a;
            }
        }

        if (!empty($v['when']) && empty($v['date_from']) && empty($v['date_to'])) {
            $now = Carbon::now('Europe/Moscow');

            if ($v['when'] === 'today') {
                $v['date_from'] = $now->copy()->startOfDay()->toDateTimeString();
                $v['date_to']   = $now->copy()->endOfDay()->toDateTimeString();
            } elseif ($v['when'] === 'weekend') {
                [$v['date_from'], $v['date_to']] = $this->weekendWindow($now);
            } elseif ($v['when'] === 'now') {
                $v['date_from'] = $now->copy()->subHours(2)->toDateTimeString();
                $v['date_to']   = $now->copy()->addHours(4)->toDateTimeString();
            }
        }
        unset($v['when']);

        if (array_key_exists('q', $v)) {
            $v['q'] = trim((string) $v['q']);
        }

        $citySlug = trim((string) ($v['city'] ?? ''));
        if ($citySlug !== '') {
            $cityId = City::query()
                ->where('slug', $citySlug)
                ->where('status', 'active')
                ->value('id');

            if (!$cityId) {
                return response()->json([
                    'data' => null,
                    'meta' => ['total' => 0],
                ]);
            }

            $v['city_id'] = (int) $cityId;
            unset($v['city']);
        }

        if (isset($v['groups'])) {
            $v['groups'] = array_map(fn (string $g) => explode(',', $g), array_values($v['groups']));
        }

        $result = $this->service->pickSurpriseWeb($v);
        $event = $result['event'];
        $total = (int) $result['total'];

        if ($event === null) {
            return response()->json([
                'data' => null,
                'meta' => ['total' => 0],
            ]);
        }

        return response()->json([
            'data' => (new WebEventResource($event))->toArray($request),
            'meta' => ['total' => $total, 'group' => $result['group'], 'again' => $result['again']],
        ]);
    }

    /**
     * GET /api/web/events/{id}/related
     * Похожие события по интересам (Interests Этап 3). Тот же формат карточки
     * что /events. Пустой список — штатно: фронт показывает фолбэк города.
     * Несуществующий id отдаёт пустой data (не 404) — блок просто скрывается.
     * grouped / grouped_by_post — как в index: схлопывание по серии и по анонсу,
     * с теми же обвесами карточки (group.dates, siblings).
     */
    public function related(int $id, Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 8);
        $limit = max(1, min($limit, 24));

        [$grouped, $groupedByPost] = $this->railGroupingFlags($request);

        $items = $this->service->relatedWeb($id, $limit, $grouped, $groupedByPost);

        return response()->json([
            'data' => WebEventResource::collection($items),
        ]);
    }

    /**
     * GET /api/web/events/{id}/companions
     * «Соседи» события по оси площадка/организатор (venue-first, иначе community) —
     * отдельное измерение от /related (интересы). Тот же формат карточки.
     * meta.label — готовый заголовок рейла; meta.venue/community_name — для see-all.
     * Пустой/несуществующий id → data:[] + meta.scope:null, HTTP 200 (блок скрыт).
     */
    public function companions(int $id, Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 10);
        $limit = max(1, min($limit, 24));

        [$grouped, $groupedByPost] = $this->railGroupingFlags($request);

        $res = $this->service->companionsWeb($id, $limit, $grouped, $groupedByPost);

        return response()->json([
            'data' => WebEventResource::collection($res['items']),
            'meta' => [
                'scope' => $res['scope'],
                'label' => $res['label'],
                'venue' => $res['venue'],
                'community_name' => $res['community_name'],
            ],
        ]);
    }

    /**
     * GET /api/web/events/{id}/next
     * Блоки «что дальше» для страницы события: в этот день, на той же площадке и
     * похожее. Одно событие попадает только в один блок; у карточки бывает
     * `reason` — короткая причина, почему она здесь. `prefer[]` — темы из колеса
     * «Чего хочется?»: подходящее идёт выше.
     */
    public function next(int $id, Request $request): JsonResponse
    {
        $v = $request->validate([
            'prefer'   => ['sometimes', 'array', 'max:20'],
            'prefer.*' => ['string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
        ]);
        $res = $this->service->nextWeb($id, array_values($v['prefer'] ?? []));

        return response()->json([
            'data' => [
                'same_day' => WebEventResource::collection($res['same_day']),
                'companions' => WebEventResource::collection($res['companions']),
                'similar' => WebEventResource::collection($res['similar']),
            ],
            'meta' => [
                'day' => $res['day'],
                'companions' => $res['companions_meta'],
            ],
        ]);
    }

    /**
     * Флаги схлопывания для рейлов (/related, /companions) — те же имена и та же
     * семантика, что у index: grouped = по серии дат, grouped_by_post = по анонсу.
     *
     * @return array{0: bool, 1: bool}
     */
    private function railGroupingFlags(Request $request): array
    {
        $v = validator($request->all(), [
            'grouped'         => ['sometimes','boolean'],
            'grouped_by_post' => ['sometimes','boolean'],
        ])->validate();

        return [
            filter_var($v['grouped'] ?? false, FILTER_VALIDATE_BOOLEAN),
            filter_var($v['grouped_by_post'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Окно пресета `weekend` в Europe/Moscow: [date_from, date_to].
     *
     * В сами выходные показываем ТЕКУЩИЕ выходные с начала сегодняшнего дня, а не
     * следующие: прежняя формула ((SATURDAY - dayOfWeek + 7) % 7) в воскресенье давала
     * +6 дней, и весь день страница «на выходных» показывала события через неделю.
     * Прошедшую субботу в воскресенье не тянем — она уже не «куда сходить».
     */
    private function weekendWindow(Carbon $now): array
    {
        // Формула переехала в Support: по ней же считает окно подборка «На
        // выходных», и подвал её поста ведёт сюда, на ?when=weekend. Две копии
        // разошлись бы на один день — и число в посте перестало бы сходиться
        // с тем, что читатель увидит по ссылке.
        [$from, $to] = \App\Support\WeekendWindow::for($now);

        return [$from->toDateTimeString(), $to->toDateTimeString()];
    }

    /**
     * Polymorphic правило: каждый элемент interests[] — либо положительный int
     * (legacy ID), либо kebab-slug (Этап 2). Mixed-array режектится отдельно
     * через rejectMixedInterests(), чтобы upgrade-путь оставался простым:
     * фронт мигрирует все категории разом, не по одной.
     */
    private function interestItemRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                if ((int) $value > 0) return;
                $fail("{$attribute}: int must be positive");
                return;
            }
            if (is_string($value) && preg_match('/^[a-z0-9-]+$/', $value) && strlen($value) <= 64) {
                return;
            }
            $fail("{$attribute}: must be positive int (legacy) or kebab-slug");
        };
    }

    private function rejectMixedInterests(Request $request, \Illuminate\Validation\Validator $validator): void
    {
        $arr = (array) $request->input('interests', []);
        if (count($arr) < 2) return;

        $hasInt = false;
        $hasSlug = false;
        foreach ($arr as $x) {
            if (is_int($x) || (is_string($x) && ctype_digit($x))) {
                $hasInt = true;
            } elseif (is_string($x)) {
                $hasSlug = true;
            }
            if ($hasInt && $hasSlug) break;
        }
        if ($hasInt && $hasSlug) {
            $validator->errors()->add('interests', 'must not mix legacy ints and slugs in one query');
        }
    }
}
