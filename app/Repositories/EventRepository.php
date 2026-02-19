<?php

namespace App\Repositories;

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventRepository
{
    /**
     * Лёгкая выдача для sitemap: только id + lastmod (atom),
     * с курсором after_id чтобы можно было чанковать.
     *
     * mode:
     * - upcoming (по умолчанию): только будущие (и чуть прошлого, как в витрине)
     * - all: всё, кроме deleted
     */
    public function listWebIdsForSitemap(int $afterId = 0, int $limit = 5000, string $mode = 'upcoming'): array
    {
        $limit = max(1, min($limit, 50000));

        $q = Event::query()
            ->select(['id', 'updated_at', 'created_at', 'start_time', 'start_date'])
            ->whereNull('deleted_at')
            ->where('id', '>', $afterId)
            ->orderBy('id', 'asc')
            ->limit($limit + 1);

        if ($mode !== 'all') {
            $todayMsk = now('Europe/Moscow')->toDateString();

            $q->where(function ($w) use ($todayMsk) {
                $w->where('start_time', '>=', now()->subDay())
                    ->orWhere(function ($x) use ($todayMsk) {
                        $x->whereNull('start_time')
                            ->whereNotNull('start_date')
                            ->where('start_date', '>=', $todayMsk);
                    });
            });
        }

        $rows = $q->get();

        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->slice(0, $limit);
        }

        $items = $rows->map(function ($e) {
            $lm = $e->updated_at ?? $e->created_at;

            return [
                'id' => (int) $e->id,
                'lastmod' => $lm ? $lm->toAtomString() : null,
            ];
        })->values()->all();

        $nextAfterId = null;
        if ($hasMore && !empty($items)) {
            $nextAfterId = $items[count($items) - 1]['id'];
        }

        return [
            'items' => $items,
            'next_after_id' => $nextAfterId,
        ];
    }

    /**
     * Пагинация будущих событий с фильтрами.
     *
     * Поддерживаемые фильтры:
     * - city: string (старый режим)
     * - city_id: int (новый режим, приоритетнее city)
     * - date_from: Y-m-d или RFC3339
     * - date_to:   Y-m-d или RFC3339
     * - q: поиск по названию/описанию события и названию/описанию сообщества (ILIKE)
     * - community_id: int
     * - interests: int[] — список ID интересов
     */
    public function paginateUpcoming(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $todayMsk = now('Europe/Moscow')->toDateString();

        $q = Event::query()
            ->whereNull('deleted_at')
            ->where(function ($w) use ($todayMsk) {
                $w->where('start_time', '>=', now()->subDay())
                    ->orWhere(function ($x) use ($todayMsk) {
                        $x->whereNull('start_time')
                            ->whereNotNull('start_date')
                            ->where('start_date', '>=', $todayMsk);
                    });
            })
            ->with([
                'community:id,name,city,avatar_url',
                'interests:id,name',
            ])
            ->orderByRaw('start_date asc nulls last')
            ->orderByRaw('start_time asc nulls last')
            ->orderBy('id', 'asc');

        if (!empty($filters['city_id'])) {
            $q->where('city_id', (int) $filters['city_id']);
        } elseif (!empty($filters['city'])) {
            // старый режим: текстовый город
            $q->where('city', 'ILIKE', trim((string)$filters['city']));
        }

        if (!empty($filters['date_from'])) {
            $fromDate = substr((string)$filters['date_from'], 0, 10);

            $q->where(function ($w) use ($filters, $fromDate) {
                $w->where('start_time', '>=', $filters['date_from'])
                    ->orWhere(function ($x) use ($fromDate) {
                        $x->whereNull('start_time')
                            ->whereNotNull('start_date')
                            ->where('start_date', '>=', $fromDate);
                    });
            });
        }

        if (!empty($filters['date_to'])) {
            $toDate = substr((string)$filters['date_to'], 0, 10);

            $q->where(function ($w) use ($filters, $toDate) {
                $w->where('start_time', '<=', $filters['date_to'])
                    ->orWhere(function ($x) use ($toDate) {
                        $x->whereNull('start_time')
                            ->whereNotNull('start_date')
                            ->where('start_date', '<=', $toDate);
                    });
            });
        }

        if (!empty($filters['community_id'])) {
            $q->where('community_id', (int) $filters['community_id']);
        }

        $qNorm = $this->normalizeQ($filters['q'] ?? null);

        if ($qNorm !== null) {
            $term = '%'.$qNorm.'%';

            $q->where(function ($w) use ($term) {
                $w->whereRaw("public.ru_normalize(events.title) LIKE ?", [$term])
                    ->orWhereRaw("public.ru_normalize(events.description) LIKE ?", [$term])
                    ->orWhereHas('community', function ($c) use ($term) {
                        $c->whereRaw("public.ru_normalize(communities.name) LIKE ?", [$term])
                            ->orWhereRaw("public.ru_normalize(communities.description) LIKE ?", [$term]);
                    });
            });
        }

        if (!empty($filters['interests']) && is_array($filters['interests'])) {
            $ids = array_filter(array_map('intval', $filters['interests']));
            if ($ids) {
                $q->whereHas('interests', function ($w) use ($ids) {
                    $w->whereIn('interests.id', $ids);
                });
            }
        }

        $paginator = $q->paginate($perPage);
        $events = $paginator->getCollection();
        $this->hydrateImages($events);

        return $paginator->setCollection($events);
    }

    /**
     * Витринная пагинация (для /api/web/events):
     * та же логика фильтров, но добавляем city_slug через join.
     */
    public function paginateUpcomingWeb(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $todayMsk = now('Europe/Moscow')->toDateString();

        $q = Event::query()
            ->select('events.*', 'ct.slug as city_slug')
            ->leftJoin('cities as ct', 'ct.id', '=', 'events.city_id')
            ->whereNull('events.deleted_at')
            ->where(function ($w) use ($todayMsk) {
                $w->where('events.start_time', '>=', now()->subDay())
                    ->orWhere(function ($x) use ($todayMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $todayMsk);
                    });
            })
            ->orderByRaw('events.start_date asc nulls last')
            ->orderByRaw('events.start_time asc nulls last')
            ->orderBy('events.id', 'asc');

        if (!empty($filters['city_id'])) {
            $q->where('events.city_id', (int) $filters['city_id']);
        }

        if (!empty($filters['date_from'])) {
            $fromDate = substr((string) $filters['date_from'], 0, 10);

            $q->where(function ($w) use ($filters, $fromDate) {
                $w->where('events.start_time', '>=', $filters['date_from'])
                    ->orWhere(function ($x) use ($fromDate) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDate);
                    });
            });
        }

        if (!empty($filters['date_to'])) {
            $toDate = substr((string) $filters['date_to'], 0, 10);

            $q->where(function ($w) use ($filters, $toDate) {
                $w->where('events.start_time', '<=', $filters['date_to'])
                    ->orWhere(function ($x) use ($toDate) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '<=', $toDate);
                    });
            });
        }

        if (!empty($filters['community_id'])) {
            $q->where('events.community_id', (int) $filters['community_id']);
        }

        $qNorm = $this->normalizeQ($filters['q'] ?? null);

        if ($qNorm !== null) {
            $term = '%'.$qNorm.'%';

            $q->where(function ($w) use ($term) {
                $w->whereRaw("public.ru_normalize(events.title) LIKE ?", [$term])
                    ->orWhereRaw("public.ru_normalize(events.description) LIKE ?", [$term])
                    ->orWhereHas('community', function ($c) use ($term) {
                        $c->whereRaw("public.ru_normalize(communities.name) LIKE ?", [$term])
                            ->orWhereRaw("public.ru_normalize(communities.description) LIKE ?", [$term]);
                    });
            });
        }

        if (!empty($filters['interests']) && is_array($filters['interests'])) {
            $ids = array_filter(array_map('intval', $filters['interests']));
            if ($ids) {
                $q->whereHas('interests', function ($w) use ($ids) {
                    $w->whereIn('interests.id', $ids);
                });
            }
        }

        // free=1
        if (!empty($filters['free'])) {
            $q->where(function ($w) {
                $w->where('events.price_status', 'free')
                    ->orWhere(function ($x) {
                        $x->where('events.price_min', 0)
                            ->whereNull('events.price_max');
                    });
            });
        }

        // ===== price range + priced =====
        $priceMin = array_key_exists('price_min', $filters) ? (int) $filters['price_min'] : null;
        $priceMax = array_key_exists('price_max', $filters) ? (int) $filters['price_max'] : null;
        $priced = array_key_exists('priced', $filters)
            ? (filter_var($filters['priced'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true)
            : false;

        $hasRange = ($priceMin !== null) || ($priceMax !== null);

        // "известная цена" = free ИЛИ есть price_min/price_max
        $knownPrice = function ($w) {
            $w->where('events.price_status', 'free')
                ->orWhereNotNull('events.price_min')
                ->orWhereNotNull('events.price_max');
        };

        // "неизвестная цена" = НЕ free и оба поля цены null
        $unknownCaseSql =
            "CASE WHEN (events.price_min IS NULL AND events.price_max IS NULL AND COALESCE(events.price_status,'') <> 'free') THEN 1 ELSE 0 END";

        $minExpr = "COALESCE(events.price_min, events.price_max, CASE WHEN events.price_status='free' THEN 0 END)";
        $maxExpr = "COALESCE(events.price_max, events.price_min, CASE WHEN events.price_status='free' THEN 0 END)";

        if ($hasRange) {
            $q->where(function ($w) use ($knownPrice, $priced, $minExpr, $maxExpr, $priceMin, $priceMax) {
                // 1) известная цена + в диапазоне
                $w->where(function ($x) use ($knownPrice, $minExpr, $maxExpr, $priceMin, $priceMax) {
                    $x->where($knownPrice);

                    if ($priceMin !== null) $x->whereRaw("$maxExpr >= ?", [$priceMin]);
                    if ($priceMax !== null) $x->whereRaw("$minExpr <= ?", [$priceMax]);
                });

                // 2) если priced не включён — добавляем "без цены" в хвост выдачи
                if (!$priced) {
                    $w->orWhere(function ($x) {
                        $x->whereNull('events.price_min')
                            ->whereNull('events.price_max')
                            ->whereRaw("COALESCE(events.price_status,'') <> 'free'");
                    });
                }
            });
        } elseif ($priced) {
            // priced=1 без диапазона — просто выкидываем безценовые
            $q->where($knownPrice);
        }

        // если диапазон задан и priced НЕ включён — сортируем "без цены" в конец
        $unknownLast = $hasRange && !$priced;
        if ($unknownLast) {
            // делаем unknown-last первичным orderBy (иначе он будет только "тай-брейкером")
            $q->reorder()
                ->orderByRaw("$unknownCaseSql asc")
                ->orderByRaw('events.start_date asc nulls last')
                ->orderByRaw('events.start_time asc nulls last')
                ->orderBy('events.id', 'asc');
        }

        // tod=morning/day/evening/night — фильтр по часу старта (MSK), только когда start_time известен
        if (!empty($filters['tod'])) {
            $tod = (string) $filters['tod'];
            $q->whereNotNull('events.start_time');

            $hourExpr = "EXTRACT(HOUR FROM (events.start_time AT TIME ZONE 'Europe/Moscow'))";

            switch ($tod) {
                case 'morning': // 05–11
                    $q->whereRaw("{$hourExpr} >= 5 AND {$hourExpr} <= 11");
                    break;
                case 'day': // 12–16
                    $q->whereRaw("{$hourExpr} >= 12 AND {$hourExpr} <= 16");
                    break;
                case 'evening': // 17–22
                    $q->whereRaw("{$hourExpr} >= 17 AND {$hourExpr} <= 22");
                    break;
                case 'night': // 23–04
                    $q->where(function ($w) use ($hourExpr) {
                        $w->whereRaw("{$hourExpr} >= 23")
                            ->orWhereRaw("{$hourExpr} <= 4");
                    });
                    break;
            }
        }

        // sort/dir (whitelist)
        $sort = $filters['sort'] ?? null;
        $dir = strtolower((string) ($filters['dir'] ?? 'asc'));
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        if ($sort) {
            $q->reorder();

            if ($unknownLast) {
                $q->orderByRaw("$unknownCaseSql asc");
            }

            switch ($sort) {
                case 'start_at':
                case 'start_date':
                    $q->orderByRaw("events.start_date {$dir} nulls last")
                        ->orderByRaw("events.start_time {$dir} nulls last");
                    break;

                case 'start_time':
                    $q->orderByRaw("events.start_time {$dir} nulls last")
                        ->orderByRaw("events.start_date {$dir} nulls last");
                    break;

                case 'created_at':
                    $q->orderBy("events.created_at", $dir);
                    break;

                case 'price_min':
                    $q->orderByRaw("events.price_min {$dir} nulls last");
                    break;
            }

            $q->orderBy('events.id', 'asc');
        }

        $paginator = $q->paginate($perPage);
        $events = $paginator->getCollection();
        $this->hydrateImages($events);

        return $paginator->setCollection($events);
    }

    /**
     * Получить одно событие с деталями.
     */
    public function findWithDetails(int $id): Event
    {
        $event = Event::query()
            ->whereNull('deleted_at')
            ->with([
                'community:id,name,city,avatar_url',
                'interests:id,name',
            ])
            ->findOrFail($id);

        $this->hydrateImages(new EloquentCollection([$event]));

        return $event;
    }

    /**
     * Получить одно событие для витрины (для /api/web/events/{id})
     * + city_slug, + sources (event_sources) и остальное.
     */
    public function findWebWithDetails(int $id): Event
    {
        $event = Event::query()
            ->select('events.*', 'ct.slug as city_slug')
            ->leftJoin('cities as ct', 'ct.id', '=', 'events.city_id')
            ->whereNull('events.deleted_at')
            ->where('events.id', $id)
            ->with([
                'community:id,name,city,avatar_url',
                'interests:id,name',
                'eventSources:id,event_id,source,post_external_id,external_url,published_at,images,generated_link,social_link_id',
                'originalPost:id,text',
            ])
            ->firstOrFail();

        $this->hydrateImages(new EloquentCollection([$event]));

        return $event;
    }

    private function hydrateImages(EloquentCollection $events): void
    {
        if ($events->isEmpty()) return;

        $eventIds = $events->pluck('id')->all();
        $postIds  = $events->pluck('original_post_id')->filter()->unique()->values()->all();

        $esRows = DB::table('event_sources')
            ->select('event_id', 'images')
            ->whereIn('event_id', $eventIds)
            ->get()
            ->groupBy('event_id')
            ->map(function (Collection $rows) {
                $all = [];
                foreach ($rows as $r) {
                    $arr = is_string($r->images) ? json_decode($r->images, true) : $r->images;
                    if (is_array($arr)) {
                        foreach ($arr as $u) {
                            if (is_string($u) && $u !== '') $all[] = $u;
                        }
                    }
                }
                $seen = [];
                $uniq = [];
                foreach ($all as $u) {
                    if (!isset($seen[$u])) {
                        $seen[$u] = true;
                        $uniq[] = $u;
                    }
                }
                return $uniq;
            });

        $cpRows = collect();
        if ($postIds) {
            $cpRows = DB::table('attachments')
                ->select('parent_id', 'type', 'url', 'preview_url', 'order', 'id')
                ->where('parent_type', 'App\\Models\\ContextPost')
                ->whereIn('parent_id', $postIds)
                ->orderBy('order')
                ->orderBy('id')
                ->get()
                ->groupBy('parent_id')
                ->map(function (Collection $rows) {
                    $urls = [];
                    foreach ($rows as $r) {
                        if (in_array($r->type, ['image', 'photo'], true)) {
                            $u = $r->url ?: $r->preview_url;
                            if (is_string($u) && $u !== '') $urls[] = $u;
                        }
                    }
                    $seen = [];
                    $uniq = [];
                    foreach ($urls as $u) {
                        if (!isset($seen[$u])) {
                            $seen[$u] = true;
                            $uniq[] = $u;
                        }
                    }
                    return $uniq;
                });
        }

        $evRows = DB::table('attachments')
            ->select('parent_id', 'type', 'url', 'preview_url', 'order', 'id')
            ->where('parent_type', 'App\\Models\\Event')
            ->whereIn('parent_id', $eventIds)
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id')
            ->map(function (Collection $rows) {
                $urls = [];
                foreach ($rows as $r) {
                    if (in_array($r->type, ['image', 'photo'], true)) {
                        $u = $r->url ?: $r->preview_url;
                        if (is_string($u) && $u !== '') $urls[] = $u;
                    }
                }
                $seen = [];
                $uniq = [];
                foreach ($urls as $u) {
                    if (!isset($seen[$u])) {
                        $seen[$u] = true;
                        $uniq[] = $u;
                    }
                }
                return $uniq;
            });

        $events->each(function (Event $e) use ($esRows, $cpRows, $evRows) {
            $images = $esRows->get($e->id, []);
            if (empty($images) && $e->original_post_id) {
                $images = $cpRows->get($e->original_post_id, []);
            }
            if (empty($images)) {
                $images = $evRows->get($e->id, []);
            }

            $images = array_values($images);

            $e->setAttribute('images', $images);
            $e->setAttribute('poster', $images[0] ?? null);
        });
    }

    private function normalizeQ(?string $q): ?string
    {
        $q = trim((string)$q);
        if ($q === '') return null;

        $q = mb_strtolower($q);
        // после strtolower достаточно заменить только ё
        $q = str_replace('ё', 'е', $q);

        return $q;
    }
}
