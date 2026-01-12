<?php

namespace App\Http\Controllers\Api;

use App\Models\City;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @OA\Tag(
 *   name="Events",
 *   description="Каталог событий"
 * )
 */
class EventController extends Controller
{
    public function __construct(
        private readonly EventService $service
    ) {}

    /**
     * Список событий (пагинация).
     *
     * @OA\Get(
     *   path="/api/events",
     *   summary="Список событий",
     *   tags={"Events"},
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", minimum=1)),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", minimum=1, maximum=50)),
     *   @OA\Parameter(name="city", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="date_from", in="query", description="ISO8601", @OA\Schema(type="string", format="date-time")),
     *   @OA\Parameter(name="date_to", in="query", description="ISO8601", @OA\Schema(type="string", format="date-time")),
     *   @OA\Parameter(name="q", in="query", description="Поиск по названию", @OA\Schema(type="string")),
     *   @OA\Parameter(name="community_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(
     *     name="interests[]", in="query",
     *     description="ID интересов (несколько)",
     *     @OA\Schema(type="array", @OA\Items(type="integer"))
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="meta", type="object",
     *         @OA\Property(property="current_page", type="integer"),
     *         @OA\Property(property="per_page", type="integer"),
     *         @OA\Property(property="total", type="integer"),
     *         @OA\Property(property="last_page", type="integer")
     *       ),
     *       @OA\Property(property="data", type="array",
     *         @OA\Items(type="object",
     *           @OA\Property(property="id", type="integer"),
     *           @OA\Property(property="title", type="string"),
     *           @OA\Property(property="start_time", type="string", format="date-time"),
     *           @OA\Property(property="end_time", type="string", format="date-time", nullable=true),
     *           @OA\Property(property="city", type="string", nullable=true),
     *           @OA\Property(property="address", type="string", nullable=true),
     *           @OA\Property(property="description", type="string", nullable=true),
     *           @OA\Property(property="external_url", type="string", nullable=true),
     *           @OA\Property(property="community", type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="city", type="string", nullable=true),
     *             @OA\Property(property="avatar_url", type="string", nullable=true)
     *           ),
     *           @OA\Property(property="interests", type="array", @OA\Items(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string")
     *           )),
     *           @OA\Property(property="images", type="array", @OA\Items(type="string")),
     *           @OA\Property(property="poster", type="string", nullable=true)
     *         )
     *       )
     *     )
     *   )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page'         => ['sometimes','integer','min:1'],
            'per_page'     => ['sometimes','integer','min:1','max:50'],
            // city — это slug
            'city'         => ['sometimes','string','max:64','regex:/^[a-z0-9-]+$/'],
            'date_from'    => ['sometimes','date'],
            'date_to'      => ['sometimes','date'],
            'q'            => ['sometimes','string','max:255'],
            'community_id' => ['sometimes','integer'],
            'interests'    => ['sometimes','array'],
            'interests.*'  => ['integer'],
        ]);

        $pageNum  = (int) ($validated['page'] ?? 1);

        $perPage  = (int) ($validated['per_page'] ?? 20);
        $perPage  = max(1, min($perPage, 50));

        $filters = $validated;
        unset($filters['per_page'], $filters['page']);

        // city=slug -> city_id -> фильтрация по events.city_id
        $citySlug = trim((string) ($validated['city'] ?? ''));
        if ($citySlug !== '') {
            $cityId = City::query()
                ->where('slug', $citySlug)
                ->value('id');

            if (!$cityId) {
                // slug не найден — отдаём пустую пагинацию, но per_page оставляем как у запроса
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

            $filters['city_id'] = (int) $cityId;
            unset($filters['city']);
        }

        $page = $this->service->list($filters, $perPage);

        return response()->json([
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
            'data' => $page->items(),
        ]);
    }

    /**
     * Получить событие по ID.
     *
     * @OA\Get(
     *   path="/api/events/{id}",
     *   summary="Детали события",
     *   tags={"Events"},
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="id", type="integer"),
     *       @OA\Property(property="title", type="string"),
     *       @OA\Property(property="start_time", type="string", format="date-time"),
     *       @OA\Property(property="end_time", type="string", format="date-time", nullable=true),
     *       @OA\Property(property="city", type="string", nullable=true),
     *       @OA\Property(property="address", type="string", nullable=true),
     *       @OA\Property(property="description", type="string", nullable=true),
     *       @OA\Property(property="external_url", type="string", nullable=true),
     *       @OA\Property(property="community", type="object",
     *         @OA\Property(property="id", type="integer"),
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="city", type="string", nullable=true),
     *         @OA\Property(property="avatar_url", type="string", nullable=true)
     *       ),
     *       @OA\Property(property="interests", type="array", @OA\Items(
     *         type="object",
     *         @OA\Property(property="id", type="integer"),
     *         @OA\Property(property="name", type="string")
     *       )),
     *       @OA\Property(property="images", type="array", @OA\Items(type="string")),
     *       @OA\Property(property="poster", type="string", nullable=true)
     *     )
     *   ),
     *   @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $event = $this->service->get($id);
        return response()->json($event);
    }
}
