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
            ->orderByRaw('start_time asc nulls last');

        if (!empty($filters['city_id'])) {
            $q->where('city_id', (int) $filters['city_id']);
        } elseif (!empty($filters['city'])) {
            $q->where('city', 'ILIKE', $filters['city']);
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

        if (!empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $q->where(function ($w) use ($term) {
                $w->where('title', 'ILIKE', $term)
                    ->orWhere('description', 'ILIKE', $term)
                    ->orWhereHas('community', function ($c) use ($term) {
                        $c->where('name', 'ILIKE', $term)
                            ->orWhere('description', 'ILIKE', $term);
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
            ->orderByRaw('events.start_time asc nulls last');

        if (!empty($filters['city_id'])) {
            $q->where('events.city_id', (int) $filters['city_id']);
        }

        if (!empty($filters['date_from'])) {
            $fromDate = substr((string)$filters['date_from'], 0, 10);

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
            $toDate = substr((string)$filters['date_to'], 0, 10);

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

        if (!empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $q->where(function ($w) use ($term) {
                $w->where('events.title', 'ILIKE', $term)
                    ->orWhere('events.description', 'ILIKE', $term)
                    ->orWhereHas('community', function ($c) use ($term) {
                        $c->where('name', 'ILIKE', $term)
                            ->orWhere('description', 'ILIKE', $term);
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
}
