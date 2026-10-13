<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Interest;
use App\Support\SearchMatch;
use App\Support\SearchVocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Автодополнение поиска на сайте: как дописать набираемое слово (`words`) и какая тема подходит
 * (`topics`). Сайт ставит варианты в строку быстрых фильтров.
 */
class SearchSuggestController extends Controller
{
    // С двух букв подходит почти любая тема.
    private const TOPIC_MIN_LEN = 3;

    public function __invoke(Request $request): JsonResponse
    {
        $v = $request->validate([
            'city' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'q' => ['required', 'string', 'max:100'],
        ]);

        $cityId = null;
        if (! empty($v['city'])) {
            $cityId = City::query()->where('slug', $v['city'])->where('status', 'active')->value('id');
            if (! $cityId) {
                return response()->json(['words' => [], 'topics' => []]);
            }
        }
        $match = SearchMatch::from($v['q']);

        return response()->json([
            'words' => $cityId !== null ? SearchVocabulary::complete((int) $cityId, $v['q']) : [],
            'topics' => $match !== null && mb_strlen($match->text) >= self::TOPIC_MIN_LEN ? $this->topics($match) : [],
        ]);
    }

    /**
     * Тема, где слово названия начинается с набранного («игр» — «Квизы и игры»), а если такой
     * нет, тема со словом, которое с него начинается («конц» — «Музыка» по слову «концерт»).
     * Нажатие ставит фильтр темы.
     *
     * @return list<array{slug: string, name: string}>
     */
    private function topics(SearchMatch $match): array
    {
        $byName = ['public.ru_normalize(interests.name) ~ ?', ['(^|[^[:alnum:]])'.$match->text]];
        $words = "trim(regexp_replace(public.ru_normalize(%s), '[^[:alnum:]]+', ' ', 'g'))";

        return Interest::query()
            ->where(function ($w) use ($match, $words, $byName) {
                $w->whereRaw(...$byName)
                    ->orWhereExists(fn ($a) => $a->selectRaw('1')
                        ->from('interest_aliases as a')
                        ->whereColumn('a.interest_id', 'interests.id')
                        ->whereRaw(sprintf($words, 'a.alias').' LIKE ?', [$match->text.'%']));
            })
            ->orderByRaw("CASE WHEN {$byName[0]} THEN 0 ELSE 1 END", $byName[1])
            ->orderByRaw('interests.parent_id IS NOT NULL')
            ->orderBy('interests.id')
            ->limit(1)
            ->get(['slug', 'name'])
            ->map(fn (Interest $i) => ['slug' => (string) $i->slug, 'name' => (string) $i->name])
            ->all();
    }
}
