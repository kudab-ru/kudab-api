<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebEventResource;
use App\Models\City;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\WebEventDetailResource;

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
            'q'            => ['sometimes','string','max:255'],
            'community_id' => ['sometimes','integer'],
            'interests'    => ['sometimes','array'],
            'interests.*'  => ['integer'],
        ])->validate();

        $pageNum = (int) ($v['page'] ?? 1);

        $perPage = (int) ($v['per_page'] ?? 20);
        $perPage = max(1, min($perPage, 50));

        unset($v['page'], $v['per_page']);

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
