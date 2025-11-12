<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Services\CityService;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function __construct(private readonly CityService $service) {}

    public function index(Request $r)
    {
        $v = validator($r->all(), [
            'q'         => ['sometimes','string','max:255'],
            'country'   => ['sometimes','string','size:2'],
            'status'    => ['sometimes','in:active,disabled,limited'],
            'lat'       => ['sometimes','numeric','between:-90,90'],
            'lon'       => ['sometimes','numeric','between:-180,180'],
            'radius_m'  => ['sometimes','integer','min:100','max:200000'],
            'per_page'  => ['sometimes','integer','min:1','max:200'],
        ])->validate();

        $per = min((int)($v['per_page'] ?? 20), (int)config('bot.max_per_page', 50));
        unset($v['per_page']);

        $page = $this->service->list($v, $per);
        return CityResource::collection($page->appends($r->query()));
    }

    public function show(int $city)
    {
        return new CityResource($this->service->get($city));
    }
}
