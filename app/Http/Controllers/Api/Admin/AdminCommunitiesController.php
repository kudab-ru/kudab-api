<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Communities\AdminCommunitiesIndexRequest;
use App\Http\Requests\Admin\Communities\AdminCommunitiesStoreRequest;
use App\Http\Requests\Admin\Communities\AdminCommunitiesUpdateRequest;
use App\Http\Resources\Admin\CommunityResource as AdminCommunityResource;
use App\Models\Community;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminCommunitiesController extends Controller
{
    public function index(AdminCommunitiesIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $currentPage = (int)($validated['page'] ?? 1);
        $perPage = max(1, min((int)($validated['per_page'] ?? 20), 100));

        $communitiesQuery = Community::query()
            ->with(['city:id,name,slug']);

        $withDeleted = (bool)($validated['with_deleted'] ?? false);
        $onlyDeleted = (bool)($validated['only_deleted'] ?? false);

        if ($withDeleted || $onlyDeleted) {
            $communitiesQuery->withTrashed();
        }
        if ($onlyDeleted) {
            $communitiesQuery->onlyTrashed();
        }

        if (!empty($validated['city_id'])) {
            $communitiesQuery->where('city_id', (int)$validated['city_id']);
        }

        if (!empty($validated['verification_status'])) {
            $communitiesQuery->where('verification_status', (string)$validated['verification_status']);
        }

        if (!empty($validated['q'])) {
            $term = '%'.trim((string)$validated['q']).'%';

            $communitiesQuery->where(function ($where) use ($term) {
                $where->where('name', 'ILIKE', $term)
                    ->orWhere('description', 'ILIKE', $term);
            });
        }

        $sort = (string)($validated['sort'] ?? 'id');
        $dir = strtolower((string)($validated['dir'] ?? 'desc'));
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        // аккуратнее с nulls в last_checked_at (Postgres)
        if ($sort === 'last_checked_at') {
            $communitiesQuery->orderByRaw("last_checked_at {$dir} nulls last")->orderBy('id', 'desc');
        } else {
            $communitiesQuery->orderBy($sort, $dir);
        }

        $page = $communitiesQuery->paginate($perPage, ['*'], 'page', $currentPage);

        $items = AdminCommunityResource::collection($page->getCollection())->toArray($request);

        return response()->json([
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'data' => $items,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $community = Community::withTrashed()
            ->with(['city:id,name,slug'])
            ->findOrFail($id);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ]);
    }

    public function store(AdminCommunitiesStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $community = new Community();
        $community->fill($data);

        if (array_key_exists('city_id', $data)) {
            $community->city_id = $data['city_id'];
        }

        $community->save();

        $community = $community->fresh(['city:id,name,slug']);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ], 201);
    }

    public function update(AdminCommunitiesUpdateRequest $request, int $id): JsonResponse
    {
        $community = Community::query()->findOrFail($id);

        $data = $request->validated();

        $community->fill($data);

        if (array_key_exists('city_id', $data)) {
            $community->city_id = $data['city_id'];
        }

        $community->save();

        $community = $community->fresh(['city:id,name,slug']);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ]);
    }

    public function destroy(int|string $id): JsonResponse
    {
        $community = Community::withTrashed()->findOrFail($id);

        if (!$community->trashed()) {
            $community->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $community = Community::withTrashed()->findOrFail($id);

        if ($community->trashed()) {
            $community->restore();
        }

        $community = $community->refresh()->load(['city:id,name,slug']);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ]);
    }
}
