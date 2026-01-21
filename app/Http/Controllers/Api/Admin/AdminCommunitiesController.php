<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Resources\Admin\CommunityResource as AdminCommunityResource;
use App\Models\Community;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AdminCommunitiesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','min:1','max:100'],

            'q' => ['sometimes','string','max:255'],
            'city_id' => ['sometimes','integer','min:1'],
            'verification_status' => ['sometimes','string','max:64'],

            'with_deleted' => ['sometimes','boolean'],
            'only_deleted' => ['sometimes','boolean'],

            'sort' => ['sometimes', Rule::in(['id','name','created_at','updated_at','last_checked_at'])],
            'dir'  => ['sometimes', Rule::in(['asc','desc'])],
        ]);

        $pageNum = (int)($validated['page'] ?? 1);
        $perPage = max(1, min((int)($validated['per_page'] ?? 20), 100));

        $q = Community::query()->with(['city:id,name,slug']);

        $withDeleted = (bool)($validated['with_deleted'] ?? false);
        $onlyDeleted = (bool)($validated['only_deleted'] ?? false);

        if ($withDeleted || $onlyDeleted) $q->withTrashed();
        if ($onlyDeleted) $q->onlyTrashed();

        if (!empty($validated['city_id'])) {
            $q->where('city_id', (int)$validated['city_id']);
        }

        if (!empty($validated['verification_status'])) {
            $q->where('verification_status', (string)$validated['verification_status']);
        }

        if (!empty($validated['q'])) {
            $term = '%'.trim((string)$validated['q']).'%';
            $q->where(function ($w) use ($term) {
                $w->where('name', 'ILIKE', $term)
                    ->orWhere('description', 'ILIKE', $term)
                    ->orWhere('external_id', 'ILIKE', $term);
            });
        }

        $sort = (string)($validated['sort'] ?? 'id');
        $dir  = strtolower((string)($validated['dir'] ?? 'desc'));
        $dir  = $dir === 'asc' ? 'asc' : 'desc';

        $page = $q->orderBy($sort, $dir)
            ->paginate($perPage, ['*'], 'page', $pageNum);

        $items = $page->getCollection()
            ->map(fn ($c) => (new AdminCommunityResource($c))->toArray($request))
            ->values()
            ->all();

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'source' => ['nullable','string','max:64'],
            'avatar_url' => ['nullable','string','max:2048'],
            'external_id' => ['nullable','string','max:255'],
            'city_id' => ['nullable','integer','min:1'],
        ]);

        $community = new Community();
        $community->fill($data);

        if (array_key_exists('city_id', $data)) $community->city_id = $data['city_id'];

        $community->save();

        $community = $community->fresh(['city:id,name,slug']);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $community = Community::query()->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes','string','max:255'],
            'description' => ['sometimes','nullable','string'],
            'source' => ['sometimes','nullable','string','max:64'],
            'avatar_url' => ['sometimes','nullable','string','max:2048'],
            'external_id' => ['sometimes','nullable','string','max:255'],
            'city_id' => ['sometimes','nullable','integer','min:1'],
        ]);

        $community->fill($data);

        if (array_key_exists('city_id', $data)) $community->city_id = $data['city_id'];

        $community->save();

        $community = $community->fresh(['city:id,name,slug']);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ]);
    }

    public function destroy(int $id): JsonResponse
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
