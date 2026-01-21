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
    public function cities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes','string','max:255'],
            'limit' => ['sometimes','integer','min:1','max:50'],

            // preload
            'id' => ['sometimes','integer','min:1'],
            'ids' => ['sometimes','array'],
            'ids.*' => ['integer','min:1'],
        ]);

        $limit = (int)($validated['limit'] ?? 20);

        $q = City::query()
            ->select(['id','name'])
            ->orderBy('name', 'asc');

        if (!empty($validated['id'])) {
            $q->where('id', (int)$validated['id']);
        } elseif (!empty($validated['ids']) && is_array($validated['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $validated['ids'])));
            if ($ids) $q->whereIn('id', $ids);
        } elseif (!empty($validated['q'])) {
            $term = '%'.trim((string)$validated['q']).'%';
            $q->where('name', 'ILIKE', $term);
        }

        $items = $q->limit($limit)->get()->map(fn ($c) => [
            'id' => $c->id,
            'label' => (string)$c->name,
        ])->values()->all();

        return response()->json(['data' => $items]);
    }

    public function communities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes','string','max:255'],
            'limit' => ['sometimes','integer','min:1','max:50'],

            // фильтр по городу (полезно для формы события)
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

        $q = Community::query()
            ->select(['id','name','city_id','deleted_at'])
            ->with(['city:id,name'])
            ->orderBy('name', 'asc');

        $withDeleted = (bool)($validated['with_deleted'] ?? false);
        $onlyDeleted = (bool)($validated['only_deleted'] ?? false);

        if ($withDeleted || $onlyDeleted) $q->withTrashed();
        if ($onlyDeleted) $q->onlyTrashed();

        if (!empty($validated['city_id'])) {
            $q->where('city_id', (int)$validated['city_id']);
        }

        if (!empty($validated['id'])) {
            $q->where('id', (int)$validated['id']);
        } elseif (!empty($validated['ids']) && is_array($validated['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $validated['ids'])));
            if ($ids) $q->whereIn('id', $ids);
        } elseif (!empty($validated['q'])) {
            $term = '%'.trim((string)$validated['q']).'%';

            // ВАЖНО: без external_id (у тебя его нет в таблице)
            $q->where(function ($w) use ($term) {
                $w->where('name', 'ILIKE', $term)
                    ->orWhere('description', 'ILIKE', $term);
            });
        }

        $items = $q->limit($limit)->get()->map(function ($c) {
            $label = (string)$c->name;

            $city = $c->city;
            if ($city) {
                $cityName = (string)$city->name;

                // добавляем город только если его ещё нет в названии
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
        $validated = $request->validate([
            'q' => ['sometimes','string','max:255'],
            'limit' => ['sometimes','integer','min:1','max:50'],

            // preload
            'id' => ['sometimes','integer','min:1'],
            'ids' => ['sometimes','array'],
            'ids.*' => ['integer','min:1'],
        ]);

        $limit = (int)($validated['limit'] ?? 20);

        $q = Interest::query()
            ->select(['id','name'])
            ->orderBy('name', 'asc');

        if (!empty($validated['id'])) {
            $q->where('id', (int)$validated['id']);
        } elseif (!empty($validated['ids']) && is_array($validated['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $validated['ids'])));
            if ($ids) $q->whereIn('id', $ids);
        } elseif (!empty($validated['q'])) {
            $term = '%'.trim((string)$validated['q']).'%';
            $q->where('name', 'ILIKE', $term);
        }

        $items = $q->limit($limit)->get()->map(fn ($i) => [
            'id' => $i->id,
            'label' => (string)$i->name,
        ])->values()->all();

        return response()->json(['data' => $items]);
    }
}
