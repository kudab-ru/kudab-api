<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Resources\WebVenueDetailResource;
use App\Http\Resources\WebVenueResource;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Public-frontend venue endpoints (PR4):
 *   GET /api/web/venues             — каталог с фильтрами;
 *   GET /api/web/venues/map         — geojson FeatureCollection для карты;
 *   GET /api/web/venues/{id}        — детальная карточка + future events.
 *
 * `cover_image_url` (A4(a)) — proxy картинки первого event'а через
 * EventSource.images. Один subquery на запрос, без N+1.
 */
class VenuesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cityId = $this->resolveCityId($request);

        $perPage = max(1, min((int) $request->input('per_page', 20), 50));
        $q       = trim((string) $request->input('q', ''));
        $kind    = trim((string) $request->input('kind', ''));

        $query = $this->baseQuery()
            ->when($cityId !== null, fn ($qq) => $qq->where('venues.city_id', $cityId))
            ->when($q !== '',    fn ($qq) => $qq->where('venues.name', 'ILIKE', '%' . $q . '%'))
            ->when($kind !== '', fn ($qq) => $qq->where('venues.kind', $kind))
            ->orderByRaw('events_count DESC NULLS LAST')
            ->orderBy('venues.name');

        $page = $query->paginate($perPage);

        return response()->json([
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
            'data' => WebVenueResource::collection($page->items())->toArray($request),
        ]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $venue = $this->baseQuery()
            ->where('venues.id', $id)
            ->first();

        if ($venue === null) {
            return response()->json(['error' => 'venue_not_found'], 404);
        }

        $venue->load('city:id,name,slug');

        return response()->json([
            'data' => (new WebVenueDetailResource($venue))->toArray($request),
        ]);
    }

    public function map(Request $request): JsonResponse
    {
        $cityId = $this->resolveCityId($request);

        $rows = Venue::query()
            ->active()
            ->whereNotNull('location')
            ->when($cityId !== null, fn ($qq) => $qq->where('city_id', $cityId))
            ->select('id', 'slug', 'name', 'kind', 'latitude', 'longitude')
            ->get();

        $features = [];
        foreach ($rows as $r) {
            if ($r->latitude === null || $r->longitude === null) continue;
            $features[] = [
                'type'       => 'Feature',
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $r->longitude, (float) $r->latitude],
                ],
                'properties' => [
                    'id'   => (int) $r->id,
                    'slug' => (string) $r->slug,
                    'name' => (string) $r->name,
                    'kind' => $r->kind !== null ? (string) $r->kind : null,
                ],
            ];
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Базовый Eloquent-query с city_slug, events_count и cover_image_url
     * подзапросами (один SQL, без N+1).
     */
    private function baseQuery()
    {
        $eventsCountSql = '(SELECT COUNT(*) FROM events e
            WHERE e.venue_id = venues.id AND e.deleted_at IS NULL)';

        // Первая картинка из event_sources.images первого (по дате) event'а
        // на этом venue. images — postgres `json` (не jsonb), используем
        // json_array_length + ->>0 для безопасного доступа.
        $coverSql = "(SELECT es.images->>0
            FROM events e
            JOIN event_sources es ON es.event_id = e.id
            WHERE e.venue_id = venues.id
              AND e.deleted_at IS NULL
              AND es.images IS NOT NULL
              AND json_array_length(es.images) > 0
            ORDER BY e.start_time ASC NULLS LAST
            LIMIT 1)";

        return Venue::query()
            ->active()
            ->leftJoin('cities as ct', 'ct.id', '=', 'venues.city_id')
            ->select([
                'venues.*',
                DB::raw('ct.slug as city_slug'),
                DB::raw($eventsCountSql . ' as events_count'),
                DB::raw($coverSql . ' as cover_image_url'),
            ]);
    }

    private function resolveCityId(Request $request): ?int
    {
        $cityIdInt = $request->input('city_id');
        if ($cityIdInt !== null && $cityIdInt !== '') {
            return (int) $cityIdInt;
        }

        $citySlug = trim((string) $request->input('city', ''));
        if ($citySlug !== '') {
            $id = DB::table('cities')->where('slug', $citySlug)->value('id');
            return $id ? (int) $id : null;
        }

        return null;
    }
}
