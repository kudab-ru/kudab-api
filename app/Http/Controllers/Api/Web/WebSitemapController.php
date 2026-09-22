<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Repositories\EventRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WebSitemapController extends Controller
{
    public function __construct(
        private readonly EventRepository $events
    ) {}

    public function events(Request $request): JsonResponse
    {
        $v = validator($request->all(), [
            'after_id' => ['sometimes','integer','min:0'],
            'limit'    => ['sometimes','integer','min:1','max:50000'],
            'mode'     => ['sometimes', Rule::in(['upcoming','all'])],
        ])->validate();

        $afterId = (int) ($v['after_id'] ?? 0);
        $limit   = (int) ($v['limit'] ?? 5000);
        $mode    = (string) ($v['mode'] ?? 'upcoming');

        // Короткий кэш, чтобы Nuxt не долбил базу при каждом запросе sitemap
        $cacheKey = "web:sitemap:events:mode={$mode}:after={$afterId}:limit={$limit}";

        $payload = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($afterId, $limit, $mode) {
            return $this->events->listWebIdsForSitemap($afterId, $limit, $mode);
        });

        return response()->json($payload);
    }

    /**
     * Площадки для sitemap: id, слаг и дата последнего изменения.
     *
     * Дата берётся не только из самой площадки. Страница площадки на три
     * четверти состоит из её афиши, и правка карточки события меняет страницу
     * куда чаще, чем правка названия или адреса. Поэтому lastmod — это более
     * поздняя из двух дат: когда меняли саму площадку и когда последний раз
     * трогали её предстоящее событие. Иначе робот видел бы дату полугодовой
     * давности у страницы, которая обновляется каждый день.
     */
    public function venues(Request $request): JsonResponse
    {
        $v = validator($request->all(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50000'],
        ])->validate();

        $limit = (int) ($v['limit'] ?? 5000);

        $payload = Cache::remember("web:sitemap:venues:limit={$limit}", now()->addMinutes(5), function () use ($limit) {
            $lastEvent = DB::table('events')
                ->selectRaw('venue_id, max(updated_at) as ev_updated_at')
                ->whereNull('deleted_at')
                ->whereNotNull('venue_id')
                ->where(function ($w) {
                    $w->where('start_time', '>=', now()->subDay())
                        ->orWhere(function ($x) {
                            $x->whereNull('start_time')
                                ->whereNotNull('start_date')
                                ->where('start_date', '>=', now('Europe/Moscow')->toDateString());
                        });
                })
                ->groupBy('venue_id');

            // Колонки квалифицируем таблицей: id, slug и updated_at есть и у
            // cities. На событиях этот же join однажды уронил sitemap в 500,
            // и запасной путь молча подменял выдачу — повторять не будем.
            $rows = Venue::query()
                ->select(['venues.id', 'venues.slug', 'venues.updated_at', 'le.ev_updated_at'])
                ->join('cities as ct', 'ct.id', '=', 'venues.city_id')
                ->leftJoinSub($lastEvent, 'le', 'le.venue_id', '=', 'venues.id')
                ->where('ct.status', 'active')
                ->where('venues.status', 'active')
                ->whereNull('venues.deleted_at')
                ->orderBy('venues.id')
                ->limit($limit)
                ->get();

            $items = $rows->map(function ($row) {
                $dates = array_filter([
                    $row->updated_at ? \Carbon\CarbonImmutable::parse($row->updated_at) : null,
                    $row->ev_updated_at ? \Carbon\CarbonImmutable::parse($row->ev_updated_at) : null,
                ]);

                $lastmod = $dates === [] ? null : max($dates);

                return [
                    'id' => (int) $row->id,
                    'slug' => $row->slug,
                    'lastmod' => $lastmod?->toIso8601String(),
                ];
            })->all();

            return ['items' => $items];
        });

        return response()->json($payload);
    }
}
