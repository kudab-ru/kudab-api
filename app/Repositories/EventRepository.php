<?php

namespace App\Repositories;

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventRepository
{
    private const FUZZY_MIN_LEN = 3;

    private ?bool $hasTrgm = null;
    private ?bool $hasWordSim = null;

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
        if ($hasMore) $rows = $rows->slice(0, $limit);

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

    public function paginateUpcoming(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $todayMsk = now('Europe/Moscow')->toDateString();

        $q = Event::query()
            ->whereNull('events.deleted_at')
            ->where(function ($w) use ($todayMsk) {
                $w->where('events.start_time', '>=', now()->subDay())
                    ->orWhere(function ($x) use ($todayMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $todayMsk);
                    });
            })
            ->with([
                'community:id,name,city,avatar_url',
                'interests:id,name',
            ]);

        $q->addSelect('events.*');
        $this->addImgRank($q);

        $q->orderBy('__img_rank', 'asc')
            ->orderByRaw('events.start_date asc nulls last')
            ->orderByRaw('events.start_time asc nulls last')
            ->orderBy('events.id', 'asc');

        if (!empty($filters['city_id'])) {
            $q->where('events.city_id', (int) $filters['city_id']);
        } elseif (!empty($filters['city'])) {
            $q->where('events.city', 'ILIKE', trim((string) $filters['city']));
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
            $q->addSelect('events.*')
                ->leftJoin('communities as cm', 'cm.id', '=', 'events.community_id')
                ->distinct();

            $like = '%'.$qNorm.'%';

            $token = $this->pickFuzzyToken($qNorm);
            $thr = $this->fuzzyThreshold($token);

            $fuzzyOn = $this->trgmEnabled()
                && $this->wordSimEnabled()
                && mb_strlen($token) >= self::FUZZY_MIN_LEN
                && mb_strlen($token) >= 4;

            $q->where(function ($w) use ($like, $token, $thr, $fuzzyOn) {
                $w->whereRaw("public.ru_normalize(events.title) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(events.description) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.name) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.description) LIKE ?", [$like]);

                if ($fuzzyOn) {
                    $w->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(events.title)) >= ?",
                        [$token, $thr]
                    )->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(cm.name)) >= ?",
                        [$token, $thr]
                    );
                }
            });

            if (empty($filters['sort']) && $fuzzyOn) {
                $isLikeExpr = "CASE WHEN (
                    public.ru_normalize(events.title) LIKE ?
                    OR public.ru_normalize(cm.name) LIKE ?
                ) THEN 0 ELSE 1 END";

                $scoreExpr = "GREATEST(
                    word_similarity(?, public.ru_normalize(events.title)),
                    word_similarity(?, public.ru_normalize(cm.name))
                )";

                $q->selectRaw("$isLikeExpr as __like_rank", [$like, $like]);
                $q->selectRaw("$scoreExpr as __score", [$token, $token]);

                $q->reorder()
                    ->orderBy('__img_rank', 'asc')
                    ->orderBy('__like_rank', 'asc')
                    ->orderBy('__score', 'desc')
                    ->orderByRaw('events.start_date asc nulls last')
                    ->orderByRaw('events.start_time asc nulls last')
                    ->orderBy('events.id', 'asc');
            }
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

        $events->each(function (Event $e) {
            $e->makeHidden(['__img_rank', '__like_rank', '__score', '__unknown_last']);
        });

        return $paginator->setCollection($events);
    }

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
            });

        $this->addImgRank($q);

        $q->orderBy('__img_rank', 'asc')
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
        $like = null;
        $token = null;
        $thr = null;
        $fuzzyOn = false;
        $hasDistinct = false;

        if ($qNorm !== null) {
            $q->leftJoin('communities as cm', 'cm.id', '=', 'events.community_id')
                ->distinct();

            $hasDistinct = true;

            $like = '%'.$qNorm.'%';
            $token = $this->pickFuzzyToken($qNorm);
            $thr = $this->fuzzyThreshold($token);

            $fuzzyOn = $this->trgmEnabled()
                && $this->wordSimEnabled()
                && mb_strlen($token) >= self::FUZZY_MIN_LEN
                && mb_strlen($token) >= 4;

            $q->where(function ($w) use ($like, $token, $thr, $fuzzyOn) {
                $w->whereRaw("public.ru_normalize(events.title) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(events.description) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.name) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.description) LIKE ?", [$like]);

                if ($fuzzyOn) {
                    $w->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(events.title)) >= ?",
                        [$token, $thr]
                    )->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(cm.name)) >= ?",
                        [$token, $thr]
                    );
                }
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

        if (!empty($filters['free'])) {
            $q->where(function ($w) {
                $w->where('events.price_status', 'free')
                    ->orWhere(function ($x) {
                        $x->where('events.price_min', 0)
                            ->whereNull('events.price_max');
                    });
            });
        }

        $priceMin = array_key_exists('price_min', $filters) ? (int) $filters['price_min'] : null;
        $priceMax = array_key_exists('price_max', $filters) ? (int) $filters['price_max'] : null;
        $priced = array_key_exists('priced', $filters)
            ? (filter_var($filters['priced'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true)
            : false;

        $hasRange = ($priceMin !== null) || ($priceMax !== null);

        $knownPrice = function ($w) {
            $w->where('events.price_status', 'free')
                ->orWhereNotNull('events.price_min')
                ->orWhereNotNull('events.price_max');
        };

        $unknownCaseSql =
            "CASE WHEN (events.price_min IS NULL AND events.price_max IS NULL AND COALESCE(events.price_status,'') <> 'free') THEN 1 ELSE 0 END";

        $minExpr = "COALESCE(events.price_min, events.price_max, CASE WHEN events.price_status='free' THEN 0 END)";
        $maxExpr = "COALESCE(events.price_max, events.price_min, CASE WHEN events.price_status='free' THEN 0 END)";

        if ($hasRange) {
            $q->where(function ($w) use ($knownPrice, $priced, $minExpr, $maxExpr, $priceMin, $priceMax) {
                $w->where(function ($x) use ($knownPrice, $minExpr, $maxExpr, $priceMin, $priceMax) {
                    $x->where($knownPrice);

                    if ($priceMin !== null) $x->whereRaw("$maxExpr >= ?", [$priceMin]);
                    if ($priceMax !== null) $x->whereRaw("$minExpr <= ?", [$priceMax]);
                });

                if (!$priced) {
                    $w->orWhere(function ($x) {
                        $x->whereNull('events.price_min')
                            ->whereNull('events.price_max')
                            ->whereRaw("COALESCE(events.price_status,'') <> 'free'");
                    });
                }
            });
        } elseif ($priced) {
            $q->where($knownPrice);
        }

        $unknownLast = $hasRange && !$priced;
        if ($unknownLast && $hasDistinct) {
            $q->selectRaw("$unknownCaseSql as __unknown_last");
        }

        if ($unknownLast) {
            $q->reorder();
            $q->orderBy('__img_rank', 'asc');

            if ($hasDistinct) {
                $q->orderBy('__unknown_last', 'asc');
            } else {
                $q->orderByRaw("$unknownCaseSql asc");
            }

            $q->orderByRaw('events.start_date asc nulls last')
                ->orderByRaw('events.start_time asc nulls last')
                ->orderBy('events.id', 'asc');
        }

        if (!empty($filters['tod'])) {
            $tod = (string) $filters['tod'];
            $q->whereNotNull('events.start_time');

            $hourExpr = "EXTRACT(HOUR FROM (events.start_time AT TIME ZONE 'Europe/Moscow'))";

            switch ($tod) {
                case 'morning':
                    $q->whereRaw("{$hourExpr} >= 5 AND {$hourExpr} <= 11");
                    break;
                case 'day':
                    $q->whereRaw("{$hourExpr} >= 12 AND {$hourExpr} <= 16");
                    break;
                case 'evening':
                    $q->whereRaw("{$hourExpr} >= 17 AND {$hourExpr} <= 22");
                    break;
                case 'night':
                    $q->where(function ($w) use ($hourExpr) {
                        $w->whereRaw("{$hourExpr} >= 23")
                            ->orWhereRaw("{$hourExpr} <= 4");
                    });
                    break;
            }
        }

        $sort = $filters['sort'] ?? null;
        $dir = strtolower((string) ($filters['dir'] ?? 'asc'));
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        if ($sort) {
            $q->reorder();
            $q->orderBy('__img_rank', 'asc');

            if ($unknownLast) {
                if ($hasDistinct) $q->orderBy('__unknown_last', 'asc');
                else $q->orderByRaw("$unknownCaseSql asc");
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

        if (empty($filters['sort']) && $fuzzyOn && $like !== null && $token !== null) {
            $isLikeExpr = "CASE WHEN (
                public.ru_normalize(events.title) LIKE ?
                OR public.ru_normalize(cm.name) LIKE ?
            ) THEN 0 ELSE 1 END";

            $scoreExpr = "GREATEST(
                word_similarity(?, public.ru_normalize(events.title)),
                word_similarity(?, public.ru_normalize(cm.name))
            )";

            $q->selectRaw("$isLikeExpr as __like_rank", [$like, $like]);
            $q->selectRaw("$scoreExpr as __score", [$token, $token]);

            $q->reorder();
            $q->orderBy('__img_rank', 'asc');

            if ($unknownLast) {
                if ($hasDistinct) $q->orderBy('__unknown_last', 'asc');
                else $q->orderByRaw("$unknownCaseSql asc");
            }

            $q->orderBy('__like_rank', 'asc')
                ->orderBy('__score', 'desc')
                ->orderByRaw('events.start_date asc nulls last')
                ->orderByRaw('events.start_time asc nulls last')
                ->orderBy('events.id', 'asc');
        }

        $paginator = $q->paginate($perPage);
        $events = $paginator->getCollection();
        $this->hydrateImages($events);

        $events->each(function (Event $e) {
            $e->makeHidden(['__img_rank', '__like_rank', '__score', '__unknown_last']);
        });

        return $paginator->setCollection($events);
    }

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

    private function addImgRank($q): void
    {
        $sql = "CASE WHEN (
            EXISTS (
                SELECT 1 FROM event_sources es
                WHERE es.event_id = events.id
                  AND es.images IS NOT NULL
                  AND es.images::text NOT IN ('[]','null')
                LIMIT 1
            )
            OR EXISTS (
                SELECT 1 FROM attachments a
                WHERE a.parent_type = 'App\\\\Models\\\\Event'
                  AND a.parent_id = events.id
                  AND a.type IN ('image','photo')
                  AND (a.url IS NOT NULL OR a.preview_url IS NOT NULL)
                LIMIT 1
            )
            OR EXISTS (
                SELECT 1 FROM attachments ap
                WHERE ap.parent_type = 'App\\\\Models\\\\ContextPost'
                  AND ap.parent_id = events.original_post_id
                  AND ap.type IN ('image','photo')
                  AND (ap.url IS NOT NULL OR ap.preview_url IS NOT NULL)
                LIMIT 1
            )
        ) THEN 0 ELSE 1 END";

        $q->selectRaw("$sql as __img_rank");
    }

    private function normalizeQ(?string $q): ?string
    {
        $q = trim((string) $q);
        if ($q === '') return null;

        $q = mb_strtolower($q);
        $q = str_replace('ё', 'е', $q);
        $q = str_replace('-', ' ', $q);
        $q = preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $q);
        $q = preg_replace('~\s+~u', ' ', $q);
        $q = trim($q);

        return $q !== '' ? $q : null;
    }

    private function pickFuzzyToken(string $qNorm): string
    {
        $parts = preg_split('~\s+~u', $qNorm, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) return $qNorm;

        $token = '';
        foreach ($parts as $p) {
            if (mb_strlen($p) > mb_strlen($token)) $token = $p;
        }

        return $token !== '' ? $token : $qNorm;
    }

    private function fuzzyThreshold(string $token): float
    {
        $n = mb_strlen($token);
        if ($n <= 4) return 0.20;
        if ($n <= 6) return 0.18;
        return 0.14;
    }

    private function trgmEnabled(): bool
    {
        if ($this->hasTrgm !== null) return $this->hasTrgm;

        try {
            DB::selectOne("select similarity('a','a') as s");
            $this->hasTrgm = true;
        } catch (\Throwable $e) {
            $this->hasTrgm = false;
        }

        return $this->hasTrgm;
    }

    private function wordSimEnabled(): bool
    {
        if ($this->hasWordSim !== null) return $this->hasWordSim;

        try {
            DB::selectOne("select word_similarity('a','a') as s");
            $this->hasWordSim = true;
        } catch (\Throwable $e) {
            $this->hasWordSim = false;
        }

        return $this->hasWordSim;
    }
}
