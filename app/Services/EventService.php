<?php

namespace App\Services;

use App\Models\Event;
use App\Repositories\EventRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
}
