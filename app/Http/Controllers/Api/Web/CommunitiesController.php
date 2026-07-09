<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Resources\WebCommunityResource;
use App\Models\City;
use App\Models\Community;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Публичный каталог сообществ-источников для страницы /sources.
 *
 *   GET /api/web/communities  — сообщества, у которых есть хотя бы одно
 *                               «видимое» событие (как в веб-ленте), с
 *                               числом таких событий и ссылками на оригинал.
 *
 * ВНИМАНИЕ: это отдельный публичный контроллер. НЕ переиспользуем
 * AdminCommunitiesController / Admin\CommunityResource — тот отдаёт
 * withTrashed() + verification_meta и утёк бы во внешку.
 *
 * «Видимость» события синхронизирована с EventRepository::paginateUpcomingWeb
 * (город active + не удалён + окно now(MSK)−PAST_LOOKBACK_DAYS с fallback на
 * start_date + исключение blacklisted-источников). Осознанно НЕ применяем
 * applyMainFeedTaxonomyFilter: kids/family и content_kind — это дефолтное
 * сужение главной, а источник детских событий — тоже легитимный источник.
 * При правке видимости ленты — синхронизировать здесь.
 */
class CommunitiesController extends Controller
{
    /**
     * Домены агрегаторов/тикетинг-платформ. Такое «сообщество» — не городской
     * первоисточник, а витрина. Скрываем сообщество, у которого ВСЕ ссылки ведут
     * на такой домен (Яндекс.Афиша, Qtickets…). Площадку, что просто продаёт билеты
     * через тикетинг, НЕ трогаем — у неё есть и своя VK/сайт-ссылка.
     */
    private const AGGREGATOR_HOSTS = [
        'afisha.yandex', 'yandex.ru/afisha', 'qtickets', 'kassir', 'kudago',
        'timepad', 'ponominalu', 'ticketland', 'radario', 'intickets',
        'bezantrakta', 'nethouse.events', 'afishagoroda',
    ];

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 200), 200));
        $page    = max(1, (int) $request->query('page', 1));
        $q       = trim((string) $request->query('q', ''));

        $cityId = $this->resolveCityId($request);

        // Активные города (city-gate ленты). При 1 активном городе — 1 id.
        $activeCityIds = City::query()->where('status', 'active')->pluck('id')->all();

        $nowMsk      = now('Europe/Moscow');
        $cutoffTs    = $nowMsk->copy()->subDays((int) \App\Repositories\EventRepository::PAST_LOOKBACK_DAYS);
        $fromDateMsk = $cutoffTs->toDateString();

        // Замыкание видимости события — общее для whereHas (гейт «≥1 событие»)
        // и withCount (сам счётчик), чтобы список и число всегда совпадали.
        $applyVisible = function ($eq) use ($activeCityIds, $cutoffTs, $fromDateMsk, $cityId) {
            $eq->whereNull('events.deleted_at')
                ->whereIn('events.city_id', $activeCityIds ?: [0])
                ->where(function ($w) use ($cutoffTs, $fromDateMsk) {
                    $w->where('events.start_time', '>=', $cutoffTs)
                        ->orWhere(function ($x) use ($fromDateMsk) {
                            $x->whereNull('events.start_time')
                                ->whereNotNull('events.start_date')
                                ->where('events.start_date', '>=', $fromDateMsk);
                        });
                })
                // blacklist: скрываем событие только если ВСЕ его источники black
                ->whereRaw("
                    NOT (
                        EXISTS (
                            SELECT 1 FROM event_sources es
                            JOIN community_social_links csl ON csl.id = es.social_link_id
                            WHERE es.event_id = events.id AND csl.status = 'black'
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM event_sources es2
                            LEFT JOIN community_social_links csl2 ON csl2.id = es2.social_link_id
                            WHERE es2.event_id = events.id
                              AND (es2.social_link_id IS NULL OR COALESCE(csl2.status, 'active') <> 'black')
                        )
                    )
                ");

            if ($cityId !== null) {
                $eq->where('events.city_id', $cityId);
            }
        };

        $query = Community::query()
            ->select(['communities.id', 'communities.name', 'communities.avatar_url', 'communities.city_id'])
            ->whereHas('events', $applyVisible)
            // скрыть агрегаторы/тикетинг: сообщество, у которого ВСЕ ссылки — агрегатор-домен
            ->where(function ($w) {
                $agg = self::AGGREGATOR_HOSTS;
                $w->whereHas('socialLinks', function ($lq) use ($agg) {
                    foreach ($agg as $h) {
                        $lq->where('url', 'NOT ILIKE', '%' . $h . '%');
                    }
                })->orWhereDoesntHave('socialLinks', function ($lq) use ($agg) {
                    $lq->where(function ($x) use ($agg) {
                        foreach ($agg as $h) {
                            $x->orWhere('url', 'ILIKE', '%' . $h . '%');
                        }
                    });
                });
            })
            // скрыть заблокированные источники: сообщество, у которого ВСЕ ссылки status='black'
            ->where(function ($w) {
                $w->whereHas('socialLinks', function ($lq) {
                    $lq->where(function ($x) {
                        $x->whereNull('status')->orWhere('status', '<>', 'black');
                    });
                })->orWhereDoesntHave('socialLinks', function ($lq) {
                    $lq->where('status', 'black');
                });
            })
            ->withCount(['events as events_count' => $applyVisible])
            ->with([
                'city:id,slug,name',
                // ссылки на оригинал: без забаненных (status='black'), с типом соцсети
                'socialLinks' => function ($lq) {
                    $lq->where(function ($w) {
                        $w->whereNull('status')->orWhere('status', '<>', 'black');
                    })->with('socialNetwork:id,slug');
                },
            ])
            ->when($q !== '', fn ($qq) => $qq->where('communities.name', 'ILIKE', '%' . $q . '%'))
            ->orderByDesc('events_count')
            ->orderBy('communities.name');

        $p = $query->paginate(perPage: $perPage, page: $page)->appends($request->query());

        return response()->json([
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page'     => $p->perPage(),
                'total'        => $p->total(),
                'last_page'    => $p->lastPage(),
            ],
            'data' => WebCommunityResource::collection($p->items())->toArray($request),
        ]);
    }

    private function resolveCityId(Request $request): ?int
    {
        $cityIdInt = $request->query('city_id');
        if ($cityIdInt !== null && $cityIdInt !== '') {
            return (int) $cityIdInt;
        }

        $citySlug = trim((string) $request->query('city', ''));
        if ($citySlug !== '') {
            $id = City::query()->where('slug', $citySlug)->where('status', 'active')->value('id');
            return $id ? (int) $id : null;
        }

        return null;
    }
}
