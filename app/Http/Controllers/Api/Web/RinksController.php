<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Rinks\RinkPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Страница катков сайта: /afisha/{город}/katki. */
final class RinksController extends Controller
{
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly RinkPage $page) {}

    public function show(Request $request): JsonResponse
    {
        $slug = (string) $request->query('city', 'voronezh');
        abort_unless((bool) preg_match('/^[a-z0-9-]{1,64}$/', $slug), 404);
        $city = City::query()->where('slug', $slug)->where('status', 'active')->first();
        abort_if($city === null, 404);

        $data = Cache::remember(self::cacheKey($city->slug), self::CACHE_SECONDS,
            fn () => $this->page->build($city, CarbonImmutable::now('Europe/Moscow')));

        return response()->json(['data' => $data]);
    }

    /** Правка катка в админке сбрасывает этот ключ, чтобы сайт не ждал пять минут. */
    public static function cacheKey(string $citySlug): string
    {
        return 'rinks-page:v1:'.$citySlug;
    }
}
