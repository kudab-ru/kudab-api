<?php

namespace App\Services;

use App\Models\Event;
use App\Repositories\EventRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class EventService
{
    public function __construct(
        private readonly EventRepository $repo
    ) {}

    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repo->paginateUpcoming($filters, $perPage);
    }

    /**
     * @return array{page: LengthAwarePaginator, totalEvents: int|null}
     */
    public function listWeb(array $filters, int $perPage = 20): array
    {
        return $this->repo->paginateUpcomingWeb($filters, $perPage);
    }

    /**
     * Лента прошедших событий площадки («Здесь уже проходило») — all-time,
     * в обход lookback-окна ленты. См. EventRepository::listVenuePast.
     *
     * @return array{page: LengthAwarePaginator, totalEvents: int}
     */
    public function listVenuePast(int $venueId, int $perPage = 24, int $page = 1): array
    {
        return $this->repo->listVenuePast($venueId, $perPage, $page);
    }

    /**
     * @return array{event: Event|null, total: int}
     */
    public function pickRandomWeb(array $filters): array
    {
        return $this->repo->pickRandomWeb($filters);
    }

    public function get(int $id): Event
    {
        return $this->repo->findWithDetails($id);
    }

    public function getWeb(int $id): Event
    {
        return $this->repo->findWebWithDetails($id);
    }

    /**
     * Web: похожие события по интересам (Interests Этап 3).
     * Пустая коллекция — штатно (фронт показывает фолбэк города).
     */
    public function relatedWeb(int $id, int $limit = 8): EloquentCollection
    {
        return $this->repo->relatedByInterests($id, $limit);
    }

    /**
     * Web: получить группу целиком (ленивая подгрузка для карусели).
     * Возвращаем items в том же формате, что /web/events (через WebEventResource на контроллере).
     */
    public function getWebGroup(int $groupId, int $limit = 30): array
    {
        $limit = max(1, min($limit, 50));

        $count = $this->repo->countWebGroup($groupId);
        if ($count <= 0) {
            abort(404);
        }

        $items = $this->repo->listWebGroup($groupId, $limit);
        return ['count' => $count, 'items' => $items];
    }
}
