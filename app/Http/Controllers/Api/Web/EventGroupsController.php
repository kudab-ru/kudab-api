<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebEventResource;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventGroupsController extends Controller
{
    public function __construct(
        private readonly EventService $service
    ) {}

    public function show(int $id, Request $request): JsonResponse
    {
        $v = validator($request->all(), [
            'limit' => ['sometimes','integer','min:1','max:50'],
        ])->validate();

        $limit = (int) ($v['limit'] ?? 30);

        $res = $this->service->getWebGroup($id, $limit);

        return response()->json([
            'data' => [
                'group' => [
                    'id' => (int) $id,
                    'count' => (int) $res['count'],
                ],
                // items: те же карточки, что и /web/events
                'items' => WebEventResource::collection($res['items'])->toArray($request),
            ],
        ]);
    }
}
