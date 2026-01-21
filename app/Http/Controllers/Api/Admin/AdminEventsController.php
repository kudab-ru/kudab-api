<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Resources\Admin\EventResource as AdminEventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AdminEventsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','min:1','max:100'],

            'q' => ['sometimes','string','max:255'],
            'city_id' => ['sometimes','integer','min:1'],
            'community_id' => ['sometimes','integer','min:1'],
            'status' => ['sometimes','string','max:64'],

            'date_from' => ['sometimes','date'],
            'date_to' => ['sometimes','date'],
            'free' => ['sometimes','boolean'],

            'interests' => ['sometimes','array'],
            'interests.*' => ['integer','min:1'],

            'with_deleted' => ['sometimes','boolean'],
            'only_deleted' => ['sometimes','boolean'],

            'sort' => ['sometimes', Rule::in(['id','title','start_date','start_time','created_at','updated_at','price_min'])],
            'dir'  => ['sometimes', Rule::in(['asc','desc'])],
        ]);

        $pageNum = (int)($validated['page'] ?? 1);
        $perPage = max(1, min((int)($validated['per_page'] ?? 20), 100));

        $q = Event::query()->with([
            'city:id,name,slug',
            'community:id,name,city,avatar_url',
            'interests:id,name',
        ]);

        $withDeleted = (bool)($validated['with_deleted'] ?? false);
        $onlyDeleted = (bool)($validated['only_deleted'] ?? false);

        if ($withDeleted || $onlyDeleted) $q->withTrashed();
        if ($onlyDeleted) $q->onlyTrashed();

        if (!empty($validated['city_id'])) {
            $q->where('city_id', (int)$validated['city_id']);
        }

        if (!empty($validated['community_id'])) {
            $q->where('community_id', (int)$validated['community_id']);
        }

        if (!empty($validated['status'])) {
            $q->where('status', (string)$validated['status']);
        }

        if (!empty($validated['q'])) {
            $term = '%'.trim((string)$validated['q']).'%';
            $q->where(function ($w) use ($term) {
                $w->where('title', 'ILIKE', $term)
                    ->orWhere('description', 'ILIKE', $term)
                    ->orWhereHas('community', function ($c) use ($term) {
                        $c->where('name', 'ILIKE', $term)
                            ->orWhere('description', 'ILIKE', $term);
                    });
            });
        }

        if (!empty($validated['interests']) && is_array($validated['interests'])) {
            $ids = array_values(array_filter(array_map('intval', $validated['interests'])));
            if ($ids) {
                $q->whereHas('interests', fn ($w) => $w->whereIn('interests.id', $ids));
            }
        }

        // free: важно различать отсутствие параметра и free=false
        if (array_key_exists('free', $validated) && $validated['free']) {
            $q->where(function ($w) {
                $w->where('price_status', 'free')
                    ->orWhere(function ($x) {
                        $x->where('price_min', 0)->whereNull('price_max');
                    });
            });
        }

        if (!empty($validated['date_from'])) {
            $from = (string)$validated['date_from'];
            $fromDate = substr($from, 0, 10);

            $q->where(function ($w) use ($from, $fromDate) {
                $w->where('start_time', '>=', $from)
                    ->orWhere(function ($x) use ($fromDate) {
                        $x->whereNull('start_time')
                            ->whereNotNull('start_date')
                            ->where('start_date', '>=', $fromDate);
                    });
            });
        }

        if (!empty($validated['date_to'])) {
            $to = (string)$validated['date_to'];
            $toDate = substr($to, 0, 10);

            $q->where(function ($w) use ($to, $toDate) {
                $w->where('start_time', '<=', $to)
                    ->orWhere(function ($x) use ($toDate) {
                        $x->whereNull('start_time')
                            ->whereNotNull('start_date')
                            ->where('start_date', '<=', $toDate);
                    });
            });
        }

        $sort = (string)($validated['sort'] ?? 'id');
        $dir  = strtolower((string)($validated['dir'] ?? 'desc'));
        $dir  = $dir === 'asc' ? 'asc' : 'desc';

        // сортировки с nulls last (Postgres)
        switch ($sort) {
            case 'start_date':
                $q->orderByRaw("start_date {$dir} nulls last")
                    ->orderByRaw("start_time {$dir} nulls last")
                    ->orderBy('id', 'desc');
                break;

            case 'start_time':
                $q->orderByRaw("start_time {$dir} nulls last")
                    ->orderByRaw("start_date {$dir} nulls last")
                    ->orderBy('id', 'desc');
                break;

            case 'price_min':
                $q->orderByRaw("price_min {$dir} nulls last")
                    ->orderBy('id', 'desc');
                break;

            default:
                $q->orderBy($sort, $dir);
                break;
        }

        $page = $q->paginate($perPage, ['*'], 'page', $pageNum);

        $items = $page->getCollection()
            ->map(fn ($e) => (new AdminEventResource($e))->toArray($request))
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
        $event = Event::withTrashed()
            ->with(['city:id,name,slug', 'community:id,name,city,avatar_url', 'interests:id,name'])
            ->findOrFail($id);

        return response()->json([
            'data' => (new AdminEventResource($event))->toArray($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required','string','max:255'],
            'description' => ['nullable','string'],

            'community_id' => ['nullable','integer','min:1'],
            'city_id' => ['nullable','integer','min:1'],

            'address' => ['nullable','string','max:1024'],
            'city' => ['nullable','string','max:255'],

            'start_time' => ['nullable','date'],
            'end_time' => ['nullable','date'],
            'start_date' => ['nullable','date'],

            'time_precision' => ['nullable','string','max:32'],
            'time_text' => ['nullable','string','max:255'],
            'timezone' => ['nullable','string','max:64'],

            'price_status' => ['nullable','string','max:32'],
            'price_min' => ['nullable','integer','min:0'],
            'price_max' => ['nullable','integer','min:0'],
            'price_currency' => ['nullable','string','max:16'],
            'price_text' => ['nullable','string','max:255'],
            'price_url' => ['nullable','string','max:2048'],

            'external_url' => ['nullable','string','max:2048'],
            'status' => ['nullable','string','max:64'],

            'latitude' => ['nullable','numeric','between:-90,90'],
            'longitude' => ['nullable','numeric','between:-180,180'],
            'house_fias_id' => ['nullable','string','max:255'],
            'original_post_id' => ['nullable','integer','min:1'],

            'interests' => ['sometimes','array'],
            'interests.*' => ['integer','min:1'],
        ]);

        $event = new Event();
        $event->fill($data);

        // поля, которых может не быть в fillable — ставим явно
        if (array_key_exists('city_id', $data)) $event->city_id = $data['city_id'];
        if (array_key_exists('start_date', $data)) $event->start_date = $data['start_date'];
        if (array_key_exists('time_precision', $data)) $event->time_precision = $data['time_precision'];
        if (array_key_exists('time_text', $data)) $event->time_text = $data['time_text'];
        if (array_key_exists('timezone', $data)) $event->timezone = $data['timezone'];
        if (array_key_exists('price_status', $data)) $event->price_status = $data['price_status'];
        if (array_key_exists('price_min', $data)) $event->price_min = $data['price_min'];
        if (array_key_exists('price_max', $data)) $event->price_max = $data['price_max'];
        if (array_key_exists('price_currency', $data)) $event->price_currency = $data['price_currency'];
        if (array_key_exists('price_text', $data)) $event->price_text = $data['price_text'];
        if (array_key_exists('price_url', $data)) $event->price_url = $data['price_url'];

        $event->save();

        if (array_key_exists('interests', $data)) {
            $ids = array_values(array_filter(array_map('intval', $data['interests'] ?? [])));
            $event->interests()->sync($ids);
        }

        $event = $event->fresh(['city:id,name,slug','community:id,name,city,avatar_url','interests:id,name']);

        return response()->json([
            'data' => (new AdminEventResource($event))->toArray($request),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $event = Event::query()->findOrFail($id);

        $data = $request->validate([
            'title' => ['sometimes','string','max:255'],
            'description' => ['sometimes','nullable','string'],

            'community_id' => ['sometimes','nullable','integer','min:1'],
            'city_id' => ['sometimes','nullable','integer','min:1'],

            'address' => ['sometimes','nullable','string','max:1024'],
            'city' => ['sometimes','nullable','string','max:255'],

            'start_time' => ['sometimes','nullable','date'],
            'end_time' => ['sometimes','nullable','date'],
            'start_date' => ['sometimes','nullable','date'],

            'time_precision' => ['sometimes','nullable','string','max:32'],
            'time_text' => ['sometimes','nullable','string','max:255'],
            'timezone' => ['sometimes','nullable','string','max:64'],

            'price_status' => ['sometimes','nullable','string','max:32'],
            'price_min' => ['sometimes','nullable','integer','min:0'],
            'price_max' => ['sometimes','nullable','integer','min:0'],
            'price_currency' => ['sometimes','nullable','string','max:16'],
            'price_text' => ['sometimes','nullable','string','max:255'],
            'price_url' => ['sometimes','nullable','string','max:2048'],

            'external_url' => ['sometimes','nullable','string','max:2048'],
            'status' => ['sometimes','nullable','string','max:64'],

            'latitude' => ['sometimes','nullable','numeric','between:-90,90'],
            'longitude' => ['sometimes','nullable','numeric','between:-180,180'],
            'house_fias_id' => ['sometimes','nullable','string','max:255'],
            'original_post_id' => ['sometimes','nullable','integer','min:1'],

            'interests' => ['sometimes','array'],
            'interests.*' => ['integer','min:1'],
        ]);

        $event->fill($data);

        if (array_key_exists('city_id', $data)) $event->city_id = $data['city_id'];
        if (array_key_exists('start_date', $data)) $event->start_date = $data['start_date'];
        if (array_key_exists('time_precision', $data)) $event->time_precision = $data['time_precision'];
        if (array_key_exists('time_text', $data)) $event->time_text = $data['time_text'];
        if (array_key_exists('timezone', $data)) $event->timezone = $data['timezone'];
        if (array_key_exists('price_status', $data)) $event->price_status = $data['price_status'];
        if (array_key_exists('price_min', $data)) $event->price_min = $data['price_min'];
        if (array_key_exists('price_max', $data)) $event->price_max = $data['price_max'];
        if (array_key_exists('price_currency', $data)) $event->price_currency = $data['price_currency'];
        if (array_key_exists('price_text', $data)) $event->price_text = $data['price_text'];
        if (array_key_exists('price_url', $data)) $event->price_url = $data['price_url'];

        $event->save();

        if (array_key_exists('interests', $data)) {
            $ids = array_values(array_filter(array_map('intval', $data['interests'] ?? [])));
            $event->interests()->sync($ids);
        }

        $event = $event->fresh(['city:id,name,slug','community:id,name,city,avatar_url','interests:id,name']);

        return response()->json([
            'data' => (new AdminEventResource($event))->toArray($request),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $event = Event::withTrashed()->findOrFail($id);

        if (!$event->trashed()) {
            $event->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $event = Event::withTrashed()->findOrFail($id);

        if ($event->trashed()) {
            $event->restore();
        }

        $event = $event->refresh()->load(['city:id,name,slug','community:id,name,city,avatar_url','interests:id,name']);

        return response()->json([
            'data' => (new AdminEventResource($event))->toArray($request),
        ]);
    }
}
