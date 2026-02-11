<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Repositories\EventRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class WebSitemapController extends Controller
{
    public function __construct(
        private readonly EventRepository $events
    ) {}

    public function events(Request $request): JsonResponse
    {
        $v = validator($request->all(), [
            'after_id' => ['sometimes','integer','min:0'],
            'limit'    => ['sometimes','integer','min:1','max:50000'],
            'mode'     => ['sometimes', Rule::in(['upcoming','all'])],
        ])->validate();

        $afterId = (int) ($v['after_id'] ?? 0);
        $limit   = (int) ($v['limit'] ?? 5000);
        $mode    = (string) ($v['mode'] ?? 'upcoming');

        // Короткий кэш, чтобы Nuxt не долбил базу при каждом запросе sitemap
        $cacheKey = "web:sitemap:events:mode={$mode}:after={$afterId}:limit={$limit}";

        $payload = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($afterId, $limit, $mode) {
            return $this->events->listWebIdsForSitemap($afterId, $limit, $mode);
        });

        return response()->json($payload);
    }
}
