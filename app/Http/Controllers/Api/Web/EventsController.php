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
        $v = validator($request->all(), [
            'page'         => ['sometimes','integer','min:1'],
            'per_page'     => ['sometimes','integer','min:1'],

            'city'         => ['sometimes','string','max:64','regex:/^[a-z0-9-]+$/'],

            'date_from'    => ['sometimes','date'],
            'date_to'      => ['sometimes','date'],

            'when'         => ['sometimes', Rule::in(['today','now','weekend'])],
            'free'         => ['sometimes','boolean'],

            // alias для “Для детей” (временно маппим в q)
            'kids'         => ['sometimes','boolean'],

            'q'            => ['sometimes','string','max:255'],
            'community_id' => ['sometimes','integer'],
            'interests'    => ['sometimes','array'],
            'interests.*'  => ['integer'],

            'priced'       => ['sometimes','boolean'],
            'price_min'    => ['sometimes','integer','min:0'],
            'price_max'    => ['sometimes','integer','min:0'],
            'tod'          => ['sometimes', Rule::in(['morning','day','evening','night'])],

            'sort'         => ['sometimes', Rule::in(['start_at','start_date','start_time','created_at','price_min'])],
            'dir'          => ['sometimes', Rule::in(['asc','desc'])],
        ])->validate();

        $pageNum = (int) ($v['page'] ?? 1);

        $perPage = (int) ($v['per_page'] ?? 20);
        $perPage = max(1, min($perPage, 50));

        unset($v['page'], $v['per_page']);

        // нормализуем price range (на всякий случай)
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

        // kids=1 -> если q пустой, подставляем “дет” (быстро, но URL остаётся чистым)
        $kids = !empty($v['kids']);
        unset($v['kids']);
        if ($kids) {
            $qVal = trim((string) ($v['q'] ?? ''));
            if ($qVal === '') {
                $v['q'] = 'дет';
            }
        }

        // city=slug -> city_id
        $citySlug = trim((string)($v['city'] ?? ''));
        if ($citySlug !== '') {
            $cityId = City::query()->where('slug', $citySlug)->value('id');

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

        $page = $this->service->listWeb($v, $perPage);

        return response()->json([
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
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
}
