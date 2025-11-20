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
     * - city: string
     * - date_from: Y-m-d или RFC3339
     * - date_to:   Y-m-d или RFC3339
     * - q: поиск по названию/описанию события и названию/описанию сообщества (ILIKE)
     * - community_id: int
     * - interests: int[] — список ID интересов
     */
    public function paginateUpcoming(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $q = Event::query()
            ->whereNull('deleted_at')
            ->where('start_time', '>=', now()->subDay()) // небольшая толерантность ко времени
            ->with([
                'community:id,name,city,avatar_url',
                'interests:id,name',
            ])
            ->orderBy('start_time');

        if (!empty($filters['city'])) {
            // Для Postgres регистронезависимый поиск: ILIKE
            $q->where('city', 'ILIKE', $filters['city']);
        }

        if (!empty($filters['date_from'])) {
            $q->where('start_time', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $q->where('start_time', '<=', $filters['date_to']);
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

        // вернуть тот же paginator, но с дополненными событиями
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
     * Подмешивает к моделям $event->images — массив URL (стабильный порядок).
     * Приоритет:
     *   1) event_sources.images (агрегация всех источников события)
     *   2) attachments(parent=context_post, type in [image,photo])
     *   3) attachments(parent=event,       type in [image,photo])
     */
    private function hydrateImages(EloquentCollection $events): void
    {
        if ($events->isEmpty()) return;

        $eventIds = $events->pluck('id')->all();
        $postIds  = $events->pluck('original_post_id')->filter()->unique()->values()->all();

        // 1) Изображения из event_sources.images
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
                // уникализируем, сохраняем относительный порядок
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

        // 2) Вложения у исходного поста
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
                    // уникальность + порядок
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

        // 3) Вложения прямо у события
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
                // уникальность + порядок
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

        // Сборка с приоритетами
        $events->each(function (Event $e) use ($esRows, $cpRows, $evRows) {
            $images = $esRows->get($e->id, []);
            if (empty($images) && $e->original_post_id) {
                $images = $cpRows->get($e->original_post_id, []);
            }
            if (empty($images)) {
                $images = $evRows->get($e->id, []);
            }

            // Чистый массив строк в стабильном порядке
            $images = array_values($images);

            // Массив картинок + отдельная обложка
            $e->setAttribute('images', $images);
            $e->setAttribute('poster', $images[0] ?? null);
        });
    }
}
