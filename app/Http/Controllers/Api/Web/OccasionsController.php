<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Occasions\OccasionCalendar;
use App\Services\Occasions\OccasionPage;
use App\Services\Occasions\OccasionPromo;
use App\Services\Weather\CityWeather;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Страницы праздников сайта: /afisha/{город}/{page}. */
final class OccasionsController extends Controller
{
    public function __construct(
        private readonly OccasionPage $page,
        private readonly OccasionPromo $promo,
        private readonly CityWeather $weather,
    ) {}

    /** Календарь праздников, ближайший для ленты на главной и, если передан `q`, праздник из запроса поиска. */
    public function index(Request $request): JsonResponse
    {
        $city = $this->city($request);
        $now = Carbon::now('Europe/Moscow');

        $out = ['data' => $this->page->calendar($now), 'soon' => $this->promo->soon($city, $now)];
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $out['match'] = $this->promo->match(mb_substr($q, 0, 100), $city, $now);
        }

        return response()->json($out);
    }

    public function show(Request $request, string $page): JsonResponse
    {
        $theme = OccasionCalendar::byPage($page);
        abort_if($theme === null, 404);

        $city = $this->city($request);
        $data = $this->promo->pageData($theme, $city);

        $day = (string) $request->query('day', '');
        if ($day !== '') {
            abort_unless((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $day), 404);
            $rest = $this->page->forSiteDay($data, $day);
            abort_if($rest === null, 404);

            return response()->json(['data' => ['date' => $day, 'events' => $rest]]);
        }

        // лента следующего праздника внизу страницы говорит, есть ли у него уже события
        $next = isset($data['next'][0]) ? OccasionCalendar::byPage($data['next'][0]['page']) : null;
        if ($next !== null) {
            $n = $this->promo->pageData((array) $next, $city);
            $data['next'][0] += [
                'total' => $n['occasion']['total'],
                'kinds' => array_slice(array_column(array_filter($n['kinds'], fn ($k) => $k['key'] !== 'for-kids'), 'label'), 0, 3),
            ];
        }

        // прогноз на дни праздника, как у «Выходных»: сайт ставит его у заголовка дня программы
        $window = $data['occasion']['window'] ?? null;
        $weather = $window === null ? null : $this->weather->forDays(
            $city, Carbon::parse($window['from'], 'Europe/Moscow'), Carbon::parse($window['to'], 'Europe/Moscow')->endOfDay(),
            Carbon::now('Europe/Moscow'),
        );

        return response()->json(['data' => $this->page->forSite($data) + ['weather' => $weather]]);
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
