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
