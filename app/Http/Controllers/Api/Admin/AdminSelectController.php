<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\City;
use App\Models\Community;
use App\Models\Interest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminSelectController extends Controller
{
    /**
     * Поддержка preload в обоих форматах:
     * - ids[]=1&ids[]=2
     * - ids=1,2
     * - id=5 (превращаем в ids=[5], если ids не задан)
     */
    private function normalizePreloadParams(Request $request): void
    {
        // ids может прийти строкой: "1,2,3"
        if ($request->has('ids') && is_string($request->query('ids'))) {
            $raw = trim((string)$request->query('ids'));
            if ($raw !== '') {
                $parts = preg_split('/[,\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
                $ids = array_values(array_filter(array_map('intval', $parts), fn ($v) => $v > 0));
                $request->merge(['ids' => $ids]);
            }
        }

        // id=5 -> ids=[5], если ids не передали
        if ($request->filled('id') && !$request->has('ids')) {
            $id = (int)$request->query('id');
            if ($id > 0) {
                $request->merge(['ids' => [$id]]);
            }
        }
    }

    /**
     * ORDER BY по заданному списку ids (с сохранением порядка).
     */
    private function applyIdsOrder($query, array $ids): void
    {
        if (!$ids) return;

        // CASE WHEN id=... THEN pos ... END
        $case = 'CASE';
        foreach ($ids as $pos => $id) {
            $id = (int)$id;
            $case .= " WHEN id = {$id} THEN {$pos}";
        }
        $case .= ' ELSE ' . count($ids) . ' END';

        $query->orderByRaw($case);
    }

    public function cities(Request $request): JsonResponse
    {
        $this->normalizePreloadParams($request);

        $validated = $request->validate([
            'q' => ['sometimes','string','max:255'],
            'limit' => ['sometimes','integer','min:1','max:50'],

            // preload
            'id' => ['sometimes','integer','min:1'],
            'ids' => ['sometimes','array'],
            'ids.*' => ['integer','min:1'],
        ]);

        $limit = (int)($validated['limit'] ?? 20);

        $ids = [];
        if (!empty($validated['ids']) && is_array($validated['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $validated['ids']), fn ($v) => $v > 0));
        }

        $isPreload = !empty($ids);

        $q = City::query()->select(['id','name']);

        if ($isPreload) {
            $q->whereIn('id', $ids);
            $this->applyIdsOrder($q, $ids);

            // гарантируем, что все ids влезут (но не бесконечно)
            $limit = min(max($limit, count($ids)), 200);
        } elseif (!empty($validated['q'])) {
            $term = '%'.trim((string)$validated['q']).'%';
            $q->where('name', 'ILIKE', $term)
                ->orderBy('name', 'asc');
        } else {
            $q->orderBy('name', 'asc');
        }

        $items = $q->limit($limit)->get()->map(fn ($c) => [
            'id' => $c->id,
            'label' => (string)$c->name,
        ])->values()->all();

        return response()->json(['data' => $items]);
    }

    public function communities(Request $request): JsonResponse
    {
        $this->normalizePreloadParams($request);

        $validated = $request->validate([
            'q' => ['sometimes','string','max:255'],
            'limit' => ['sometimes','integer','min:1','max:50'],

            // фильтр по городу (полезно для поиска, но НЕ для preload)
            'city_id' => ['sometimes','integer','min:1'],

            // soft deletes
            'with_deleted' => ['sometimes','boolean'],
            'only_deleted' => ['sometimes','boolean'],

            // preload
            'id' => ['sometimes','integer','min:1'],
            'ids' => ['sometimes','array'],
            'ids.*' => ['integer','min:1'],
        ]);

        $limit = (int)($validated['limit'] ?? 20);

        $ids = [];
        if (!empty($validated['ids']) && is_array($validated['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $validated['ids']), fn ($v) => $v > 0));
        }

        $isPreload = !empty($ids);

        $q = Community::query()
            ->select(['id','name','city_id','deleted_at'])
            ->with(['city:id,name'])
            ->orderBy('name', 'asc');

        $withDeleted = (bool)($validated['with_deleted'] ?? false);
        $onlyDeleted = (bool)($validated['only_deleted'] ?? false);

        // ВАЖНО:
        // - preload должен возвращать выбранные записи даже если они soft-deleted
        // - поэтому в preload включаем withTrashed() автоматически
        if ($isPreload) {
            $q->withTrashed();
        } elseif ($withDeleted || $onlyDeleted) {
            $q->withTrashed();
        }

        if ($onlyDeleted) {
            $q->onlyTrashed();
        }

        if ($isPreload) {
            $q->whereIn('id', $ids);
            $this->applyIdsOrder($q, $ids);

            $limit = min(max($limit, count($ids)), 200);
        } else {
            // city_id применяем только в режиме поиска/выбора (НЕ preload)
            if (!empty($validated['city_id'])) {
                $q->where('city_id', (int)$validated['city_id']);
            }

            if (!empty($validated['q'])) {
                $term = '%'.trim((string)$validated['q']).'%';

                $q->where(function ($w) use ($term) {
                    $w->where('name', 'ILIKE', $term)
                        ->orWhere('description', 'ILIKE', $term);
                });
            }
        }

        $items = $q->limit($limit)->get()->map(function ($c) {
            $label = (string)$c->name;

            $city = $c->city;
            if ($city) {
                $cityName = (string)$city->name;
                if ($cityName !== '' && mb_stripos($label, $cityName) === false) {
                    $label .= ' · '.$cityName;
                }
            }

            if (!is_null($c->deleted_at)) {
                $label .= ' (deleted)';
            }

            return [
                'id' => $c->id,
                'label' => $label,
            ];
        })->values()->all();

        return response()->json(['data' => $items]);
    }

    public function interests(Request $request): JsonResponse
    {
        $this->normalizePreloadParams($request);

        $validated = $request->validate([
            'q' => ['sometimes','string','max:255'],
            'limit' => ['sometimes','integer','min:1','max:50'],

            // preload
            'id' => ['sometimes','integer','min:1'],
            'ids' => ['sometimes','array'],
            'ids.*' => ['integer','min:1'],
        ]);

        $limit = (int)($validated['limit'] ?? 20);

        $ids = [];
        if (!empty($validated['ids']) && is_array($validated['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $validated['ids']), fn ($v) => $v > 0));
        }

        $isPreload = !empty($ids);

        $q = Interest::query()->select(['id','name']);

        if ($isPreload) {
            $q->whereIn('id', $ids);
            $this->applyIdsOrder($q, $ids);

            $limit = min(max($limit, count($ids)), 200);
        } elseif (!empty($validated['q'])) {
            $term = '%'.trim((string)$validated['q']).'%';
            $q->where('name', 'ILIKE', $term)
                ->orderBy('name', 'asc');
        } else {
            $q->orderBy('name', 'asc');
        }

        $items = $q->limit($limit)->get()->map(fn ($i) => [
            'id' => $i->id,
            'label' => (string)$i->name,
        ])->values()->all();

        return response()->json(['data' => $items]);
    }
}
