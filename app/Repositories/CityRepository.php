<?php

namespace App\Repositories;

use App\Models\City;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CityRepository
{
    public function paginate(array $v, int $perPage): LengthAwarePaginator
    {
        $q = City::query()
            ->select(['id','name','country_code','status','latitude','longitude']);

        // статус по умолчанию — только active
        $q->where('status', $v['status'] ?? 'active');

        if (!empty($v['country'])) {
            $q->where('country_code', strtoupper($v['country']));
        }
        if (!empty($v['q'])) {
            $term = '%'.$v['q'].'%';
            $q->whereRaw('lower(name) like lower(?)', [$term]);
        }

        if (isset($v['lat'], $v['lon'])) {
            $lat = (float) $v['lat'];
            $lon = (float) $v['lon'];
            $radius = (int)($v['radius_m'] ?? config('bot.default_radius_m', 30000));

            $q->addSelect(DB::raw(
                'ST_DistanceSphere(location, ST_SetSRID(ST_Point('.$lon.','.$lat.'),4326)) as distance_m'
            ));
            $q->whereRaw('ST_DWithin(location, ST_SetSRID(ST_Point(?, ?), 4326), ?)', [$lon, $lat, $radius]);
            $q->orderBy('distance_m');
        } else {
            $q->orderBy('name');
        }

        return $q->paginate($perPage);
    }

    public function findOrFail(int $id): City
    {
        return City::query()->findOrFail($id);
    }
}
