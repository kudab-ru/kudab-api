<?php

namespace App\Services;

use App\Repositories\CityRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Models\City;

class CityService
{
    public function __construct(private readonly CityRepository $repo) {}

    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repo->paginate($filters, $perPage);
    }

    public function get(int $id): City
    {
        return $this->repo->findOrFail($id);
    }
}
