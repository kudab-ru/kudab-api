<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Occasions\OccasionCalendar;
use App\Services\Occasions\OccasionPage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Страницы праздников сайта: /afisha/{город}/{page}. */
final class OccasionsController extends Controller
{
    /** Страница собирается из нескольких запросов и сборки поста автоматом, а меняется медленно. */
    private const CACHE_SECONDS = 600;

    /** За сколько дней до праздника главная начинает звать на его страницу. */
    private const SOON_DAYS = 14;

    public function __construct(private readonly OccasionPage $page) {}

    public function index(Request $request): JsonResponse
    {
        $city = $this->city($request);
        $now = Carbon::now('Europe/Moscow');
        $calendar = $this->page->calendar($now);

        // ближайший праздник для плашки на главной: число событий то же, что на его странице
        $soon = null;
        foreach ($calendar as $o) {
            $start = Carbon::parse($o['badge'], 'Europe/Moscow');
            $end = Carbon::parse($o['until'], 'Europe/Moscow')->endOfDay();
            if ($now->lt($start->copy()->subDays(self::SOON_DAYS)) || $now->gt($end)) {
                continue;
            }
            $data = $this->pageData((array) OccasionCalendar::byPage($o['page']), $city);
            if ($data['occasion']['total'] > 0) {
                $soon = $o + [
                    'days_left' => max(0, (int) $now->copy()->startOfDay()->diffInDays($start, false)),
                    'total' => $data['occasion']['total'],
                    'lead' => $data['occasion']['lead'],
                ];
            }
            break;
        }

        return response()->json(['data' => $calendar, 'soon' => $soon]);
    }

    public function show(Request $request, string $page): JsonResponse
    {
        $theme = OccasionCalendar::byPage($page);
        abort_if($theme === null, 404);

        return response()->json(['data' => $this->pageData($theme, $this->city($request))]);
    }

    /** @param  array<string, mixed>  $theme */
    private function pageData(array $theme, City $city): array
    {
        return Cache::remember(
            'occasion-page:v1:'.$city->slug.':'.$theme['page'],
            self::CACHE_SECONDS,
            fn () => $this->page->build($theme, $city, Carbon::now()),
        );
    }

    private function city(Request $request): City
    {
        $slug = (string) $request->query('city', 'voronezh');
        abort_unless((bool) preg_match('/^[a-z0-9-]{1,64}$/', $slug), 404);

        $city = City::query()->where('slug', $slug)->where('status', 'active')->first();
        abort_if($city === null, 404);

        return $city;
    }
}
