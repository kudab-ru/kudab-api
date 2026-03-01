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

    public function listWeb(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repo->paginateUpcomingWeb($filters, $perPage);
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
