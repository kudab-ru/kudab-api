<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Web\CityResource;
use App\Models\City;
use Illuminate\Http\Request;

class CitiesController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        // per_page cap (чтобы не было 999 и т.п.)
        $perPage = (int) $request->query('per_page', 200);
        $perPage = max(1, min(200, $perPage));

        $page = (int) $request->query('page', 1);
        $page = max(1, $page);

        $query = City::query()
            ->select(['id', 'name', 'slug'])
            ->where('status', 'active');

        if ($q !== '') {
            $qLower = mb_strtolower($q);

            $query->where(function ($w) use ($q, $qLower) {
                // Postgres: ilike ок
                $w->where('slug', 'ilike', "%{$q}%")
                    ->orWhere('name', 'ilike', "%{$q}%");

                // если есть name_ci (у тебя он есть — ты по нему бэкфиллишь)
                $w->orWhere('name_ci', 'like', "%{$qLower}%");
            });
        }

        $query->orderBy('name');

        $p = $query->paginate(perPage: $perPage, page: $page)->appends($request->query());

        $data = $p->getCollection()
            ->map(fn ($city) => (new CityResource($city))->resolve($request))
            ->values();

        return response()->json([
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page'     => $p->perPage(),
                'total'        => $p->total(),
                'last_page'    => $p->lastPage(),
            ],
            'data' => $data,
        ]);
    }
}
