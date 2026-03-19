<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Events\AdminEventsIndexRequest;
use App\Http\Requests\Admin\Events\AdminEventsStoreRequest;
use App\Http\Requests\Admin\Events\AdminEventsUpdateRequest;
use App\Http\Resources\Admin\EventResource as AdminEventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminEventsController extends Controller
{
    private function eventRelations(): array
    {
        return [
            'city:id,name,slug',
            'community:id,name,avatar_url,city_id',
            'community.city:id,name,slug',
            'interests:id,name',
        ];
    }

    public function index(AdminEventsIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $currentPage = (int)($validated['page'] ?? 1);
        $perPage = max(1, min((int)($validated['per_page'] ?? 20), 100));

        $eventsQuery = Event::query()->with($this->eventRelations());

        $withDeleted = (bool)($validated['with_deleted'] ?? false);
        $onlyDeleted = (bool)($validated['only_deleted'] ?? false);

        if ($withDeleted || $onlyDeleted) {
            $eventsQuery->withTrashed();
        }
        if ($onlyDeleted) {
            $eventsQuery->onlyTrashed();
        }

        if (!empty($validated['city_id'])) {
            $eventsQuery->where('city_id', (int)$validated['city_id']);
        }

        if (!empty($validated['community_id'])) {
            $eventsQuery->where('community_id', (int)$validated['community_id']);
        }

        if (!empty($validated['status'])) {
            $eventsQuery->where('status', (string)$validated['status']);
        }

        if (!empty($validated['q'])) {
            $term = '%'.trim((string)$validated['q']).'%';

            $eventsQuery->where(function ($where) use ($term) {
                $where->where('title', 'ILIKE', $term)
                    ->orWhere('description', 'ILIKE', $term)
                    ->orWhereHas('community', function ($communityQuery) use ($term) {
                        $communityQuery->where('name', 'ILIKE', $term)
                            ->orWhere('description', 'ILIKE', $term);
                    });
            });
        }

        if (!empty($validated['interests']) && is_array($validated['interests'])) {
            $interestIds = array_values(array_filter(array_map('intval', $validated['interests'])));
            if ($interestIds) {
                $eventsQuery->whereHas('interests', fn ($q) => $q->whereIn('interests.id', $interestIds));
            }
        }

        if (($validated['free'] ?? false) === true) {
            $eventsQuery->where(function ($where) {
                $where->where('price_status', 'free')
                    ->orWhere(function ($x) {
                        $x->where('price_min', 0)->whereNull('price_max');
                    });
            });
        }

        if (!empty($validated['date_from'])) {
            $from = (string)$validated['date_from'];
            $fromDate = substr($from, 0, 10);

            $eventsQuery->where(function ($where) use ($from, $fromDate) {
                $where->where('start_time', '>=', $from)
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

            $eventsQuery->where(function ($where) use ($to, $toDate) {
                $where->where('start_time', '<=', $to)
                    ->orWhere(function ($x) use ($toDate) {
                        $x->whereNull('start_time')
                            ->whereNotNull('start_date')
                            ->where('start_date', '<=', $toDate);
                    });
            });
        }

        $sort = (string)($validated['sort'] ?? 'id');
        $dir = strtolower((string)($validated['dir'] ?? 'desc'));
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        switch ($sort) {
            case 'start_date':
                $eventsQuery->orderByRaw("start_date {$dir} nulls last")
                    ->orderByRaw("start_time {$dir} nulls last")
                    ->orderBy('id', 'desc');
                break;

            case 'start_time':
                $eventsQuery->orderByRaw("start_time {$dir} nulls last")
                    ->orderByRaw("start_date {$dir} nulls last")
                    ->orderBy('id', 'desc');
                break;

            case 'price_min':
                $eventsQuery->orderByRaw("price_min {$dir} nulls last")
                    ->orderBy('id', 'desc');
                break;

            default:
                $eventsQuery->orderBy($sort, $dir);
                break;
        }

        $page = $eventsQuery->paginate($perPage, ['*'], 'page', $currentPage);

        $items = AdminEventResource::collection($page->getCollection())->toArray($request);

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
            ->with($this->eventRelations())
            ->findOrFail($id);

        return response()->json([
            'data' => (new AdminEventResource($event))->toArray($request),
        ]);
    }

    public function store(AdminEventsStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $interestIds = array_values(array_filter(array_map('intval', $data['interests'] ?? [])));
        unset($data['interests']);

        $event = new Event();
        $event->fill($data);

        foreach ([
                     'community_id','city_id',
                     'start_time','end_time','start_date',
                     'time_precision','time_text','timezone',
                     'price_status','price_min','price_max','price_currency','price_text','price_url',
                     'external_url','status',
                     'latitude','longitude','house_fias_id','original_post_id',
                     'address','city','description','title',
                 ] as $field) {
            if (array_key_exists($field, $data)) {
                $event->{$field} = $data[$field];
            }
        }

        // Если прислали start_time, но не прислали start_date — выставим start_date автоматически
        if (empty($data['start_date']) && !empty($data['start_time'])) {
            $event->start_date = substr((string)$data['start_time'], 0, 10);
        }

        $event->save();

        if ($request->has('interests')) {
            $event->interests()->sync($interestIds);
        }

        $event->load($this->eventRelations());

        return response()->json([
            'data' => (new AdminEventResource($event))->toArray($request),
        ], 201);
    }

    public function update(AdminEventsUpdateRequest $request, int $id): JsonResponse
    {
        $event = Event::query()->findOrFail($id);

        $data = $request->validated();

        $hasInterests = $request->has('interests');
        $interestIds = array_values(array_filter(array_map('intval', $data['interests'] ?? [])));
        unset($data['interests']);

        $event->fill($data);

        foreach ([
                     'community_id','city_id',
                     'start_time','end_time','start_date',
                     'time_precision','time_text','timezone',
                     'price_status','price_min','price_max','price_currency','price_text','price_url',
                     'external_url','status',
                     'latitude','longitude','house_fias_id','original_post_id',
                     'address','city','description','title',
                 ] as $field) {
            if (array_key_exists($field, $data)) {
                $event->{$field} = $data[$field];
            }
        }

        if (!array_key_exists('start_date', $data) && array_key_exists('start_time', $data) && !empty($data['start_time'])) {
            $event->start_date = substr((string)$data['start_time'], 0, 10);
        }

        $event->save();

        if ($hasInterests) {
            $event->interests()->sync($interestIds);
        }

        $event->load($this->eventRelations());

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

        $event->refresh()->load($this->eventRelations());

        return response()->json([
            'data' => (new AdminEventResource($event))->toArray($request),
        ]);
    }
}
