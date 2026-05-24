<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Resources\WebInterestResource;
use App\Models\Interest;
use App\Repositories\EventRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public каталог интересов (Этап 2).
 *
 *   GET /api/web/interests              — все интересы плоским массивом
 *   GET /api/web/interests?city=slug    — events_count считается в скоупе города
 *   GET /api/web/interests?parent_slug= — отфильтровать только children
 *   GET /api/web/interests?q=           — ILIKE по name
 *
 * Кэш Cache::remember 10 мин по (city, parent_slug, q). Инвалидация при
 * interests:sync (см. SyncInterestsFromCsv).
 */
class InterestsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cityId     = $this->resolveCityId($request);
        $parentSlug = trim((string) $request->input('parent_slug', '')) ?: null;
        $q          = trim((string) $request->input('q', '')) ?: null;

        $cacheKey = sprintf(
            'interests:catalog:%s:%s:%s',
            $cityId ?? 'all',
            $parentSlug ?? '-',
            $q !== null ? md5($q) : '-'
        );

        $items = Cache::remember($cacheKey, 600, function () use ($cityId, $parentSlug, $q) {
            return $this->fetch($cityId, $parentSlug, $q);
        });

        return response()->json([
            'data' => WebInterestResource::collection($items)->toArray(request()),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetch(?int $cityId, ?string $parentSlug, ?string $q)
    {
        // events_count: тот же time-window что paginateUpcomingWeb — события
        // в окне [NOW - PAST_LOOKBACK_DAYS, +∞), с soft-fallback на start_date
        // когда start_time NULL. Иначе chip-counter и /events ленты будут
        // расходиться, юзер увидит «(39)» → кликнет → получит 53 → подумает,
        // что счётчик соврал. На 119 интересов дешевле subquery, чем GROUP BY.
        $lookbackDays = EventRepository::PAST_LOOKBACK_DAYS;
        $cityClause = $cityId !== null ? ' AND e.city_id = ' . (int) $cityId : '';
        $eventsCountSql = "(SELECT COUNT(*) FROM event_interest ei
            JOIN events e ON e.id = ei.event_id
            WHERE ei.interest_id = interests.id
              AND e.deleted_at IS NULL
              AND (
                e.start_time >= (NOW() - INTERVAL '{$lookbackDays} days')
                OR (
                  e.start_time IS NULL
                  AND e.start_date IS NOT NULL
                  AND e.start_date >= ((NOW() AT TIME ZONE 'Europe/Moscow')::date - INTERVAL '{$lookbackDays} days')
                )
              ){$cityClause})";

        return Interest::query()
            ->leftJoin('interests as p', 'p.id', '=', 'interests.parent_id')
            ->when($parentSlug !== null, fn ($qq) => $qq->where('p.slug', $parentSlug))
            ->when($q !== null, fn ($qq) => $qq->where('interests.name', 'ILIKE', '%' . $q . '%'))
            ->select([
                'interests.*',
                DB::raw('p.slug as parent_slug'),
                DB::raw($eventsCountSql . ' as events_count'),
            ])
            ->orderByRaw('interests.parent_id ASC NULLS FIRST')
            ->orderBy('interests.name')
            ->get();
    }

    private function resolveCityId(Request $request): ?int
    {
        $slug = trim((string) $request->input('city', ''));
        if ($slug === '') {
            return null;
        }
        $id = DB::table('cities')->where('slug', $slug)->where('status', 'active')->value('id');
        return $id ? (int) $id : null;
    }
}
