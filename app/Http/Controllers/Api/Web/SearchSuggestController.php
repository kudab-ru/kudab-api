<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Interest;
use App\Repositories\EventRepository;
use App\Support\SearchMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/web/search/suggest — подсказки под полем поиска на сайте: события по названию и темы.
 * Площадки сайт берёт из /api/web/venues?q=, там то же правило и порядок.
 */
class SearchSuggestController extends Controller
{
    public function __construct(private readonly EventRepository $events) {}

    public function __invoke(Request $request): JsonResponse
    {
        $v = $request->validate([
            'city' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'q' => ['required', 'string', 'max:100'],
        ]);

        $match = SearchMatch::from($v['q']);
        $filters = ['q' => $v['q']];
        if (! empty($v['city'])) {
            $cityId = City::query()->where('slug', $v['city'])->where('status', 'active')->value('id');
            if (! $cityId) {
                return response()->json(['events' => [], 'topics' => []]);
            }
            $filters['city_id'] = (int) $cityId;
        }

        return response()->json([
            'events' => $this->events->suggestEvents($filters),
            'topics' => $match !== null ? $this->topics($match) : [],
        ]);
    }

    /**
     * Темы верхнего уровня, где слово названия начинается с запроса: «игр» даёт «Квизы и игры»,
     * а «из» не даёт. Нажатие на тему ставит фильтр темы вместо поиска словом.
     *
     * @return list<array{slug: string, name: string}>
     */
    private function topics(SearchMatch $match): array
    {
        return Interest::query()
            ->whereNull('parent_id')
            ->whereRaw('public.ru_normalize(name) ~ ?', ['(^|[^[:alnum:]])'.$match->text])
            ->orderBy('id')
            ->limit(2)
            ->get(['slug', 'name'])
            ->map(fn (Interest $i) => ['slug' => (string) $i->slug, 'name' => (string) $i->name])
            ->all();
    }
}
