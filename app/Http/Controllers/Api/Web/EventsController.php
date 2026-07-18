<?php

namespace App\Http\Controllers\Api\Web;

use Illuminate\Support\Arr;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebEventResource;
use App\Models\City;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\WebEventDetailResource;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

class EventsController extends Controller
{
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

            'priced'       => ['sometimes','boolean'],
            'price_min'    => ['sometimes','integer','min:0'],
            'price_max'    => ['sometimes','integer','min:0'],
            'tod'          => ['sometimes', Rule::in(['morning','day','evening','night'])],

            'sort'         => ['sometimes', Rule::in(['start_at','start_date','start_time','created_at','price_min','top'])],
            'dir'          => ['sometimes', Rule::in(['asc','desc'])],
        ]);
        $validator->after(fn ($v) => $this->rejectMixedInterests($request, $v));
        $v = $validator->validate();

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

        if (!empty($v['when']) && empty($v['date_from']) && empty($v['date_to'])) {
            $now = Carbon::now('Europe/Moscow');

            if ($v['when'] === 'today') {
                $v['date_from'] = $now->copy()->startOfDay()->toDateTimeString();
                $v['date_to']   = $now->copy()->endOfDay()->toDateTimeString();
            } elseif ($v['when'] === 'weekend') {
                // ближайшая суббота (или сегодня, если уже суббота)
                $daysToSat = (Carbon::SATURDAY - $now->dayOfWeek + 7) % 7;

                $sat = $now->copy()->addDays($daysToSat)->startOfDay();
                $sun = $sat->copy()->addDay()->endOfDay();

                $v['date_from'] = $sat->toDateTimeString();
                $v['date_to']   = $sun->toDateTimeString();
            } elseif ($v['when'] === 'now') {
                $v['date_from'] = $now->copy()->subHours(2)->toDateTimeString();
                $v['date_to']   = $now->copy()->addHours(4)->toDateTimeString();
            }
        }

        unset($v['when']);

        $kids = !empty($v['kids']);
        unset($v['kids']);
        if ($kids) {
            $qVal = trim((string) ($v['q'] ?? ''));
            if ($qVal === '') {
                $v['q'] = 'дет';
            }
        }

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

        $result = $this->service->listWeb($v, $perPage);
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

        return response()->json([
            'meta' => $meta,
            'data' => WebEventResource::collection($page->items()),
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
                $daysToSat = (Carbon::SATURDAY - $now->dayOfWeek + 7) % 7;
                $sat = $now->copy()->addDays($daysToSat)->startOfDay();
                $sun = $sat->copy()->addDay()->endOfDay();
                $v['date_from'] = $sat->toDateTimeString();
                $v['date_to']   = $sun->toDateTimeString();
            } elseif ($v['when'] === 'now') {
                $v['date_from'] = $now->copy()->subHours(2)->toDateTimeString();
                $v['date_to']   = $now->copy()->addHours(4)->toDateTimeString();
            }
        }
        unset($v['when']);

        $kids = !empty($v['kids']);
        unset($v['kids']);
        if ($kids) {
            $qVal = trim((string) ($v['q'] ?? ''));
            if ($qVal === '') {
                $v['q'] = 'дет';
            }
        }
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
                ],
            ];
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $event = $this->service->getWeb($id);

        return response()->json([
            'data' => (new WebEventDetailResource($event))->toArray(request()),
        ]);
    }

    /**
     * GET /api/web/events/random
     * Случайное событие под те же фильтры что /events, для компаса на kudab-frontend.
     * Без grouped/grouped_by_post — выбор из всех событий (включая siblings).
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
            'exclude_ids'  => ['sometimes', 'array', 'max:50'],
            'exclude_ids.*' => ['integer'],
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
                $daysToSat = (Carbon::SATURDAY - $now->dayOfWeek + 7) % 7;
                $sat = $now->copy()->addDays($daysToSat)->startOfDay();
                $sun = $sat->copy()->addDay()->endOfDay();
                $v['date_from'] = $sat->toDateTimeString();
                $v['date_to']   = $sun->toDateTimeString();
            } elseif ($v['when'] === 'now') {
                $v['date_from'] = $now->copy()->subHours(2)->toDateTimeString();
                $v['date_to']   = $now->copy()->addHours(4)->toDateTimeString();
            }
        }
        unset($v['when']);

        $kids = !empty($v['kids']);
        unset($v['kids']);
        if ($kids) {
            $qVal = trim((string) ($v['q'] ?? ''));
            if ($qVal === '') {
                $v['q'] = 'дет';
            }
        }

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

        $result = $this->service->pickRandomWeb($v);
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
            'meta' => ['total' => $total],
        ]);
    }

    /**
     * GET /api/web/events/{id}/related
     * Похожие события по интересам (Interests Этап 3). Тот же формат карточки
     * что /events. Пустой список — штатно: фронт показывает фолбэк города.
     * Несуществующий id отдаёт пустой data (не 404) — блок просто скрывается.
     */
    public function related(int $id, Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 8);
        $limit = max(1, min($limit, 24));

        $items = $this->service->relatedWeb($id, $limit);

        return response()->json([
            'data' => WebEventResource::collection($items),
        ]);
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
