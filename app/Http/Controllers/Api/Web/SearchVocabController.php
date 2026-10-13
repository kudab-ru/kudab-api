<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Interest;
use App\Support\SearchMatch;
use App\Support\SearchVocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Словарь поиска для сайта: слова и сочетания из афиши города и слова тем. Сайт загружает его
 * один раз и дописывает набираемое слово сам, без запроса на каждую букву.
 */
class SearchVocabController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $v = $request->validate([
            'city' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $cityId = City::query()->where('slug', $v['city'])->where('status', 'active')->value('id');
        $vocab = $cityId ? SearchVocabulary::forCity((int) $cityId) : ['words' => [], 'pairs' => []];

        return response()
            ->json([...$vocab, 'topics' => $this->topics()])
            ->header('Cache-Control', 'public, max-age=600');
    }

    /**
     * Темы и слова, по которым их узнают: слова темы из `interest_aliases` («концерт» — музыка),
     * приведённые к виду запроса. Верхние темы первыми.
     *
     * @return list<array{slug: string, name: string, words: list<string>}>
     */
    private function topics(): array
    {
        $aliases = DB::table('interest_aliases')->get(['interest_id', 'alias'])->groupBy('interest_id');

        return Interest::query()
            ->orderByRaw('parent_id IS NOT NULL')
            ->orderBy('id')
            ->get(['id', 'slug', 'name'])
            ->map(fn (Interest $i) => [
                'slug' => (string) $i->slug,
                'name' => (string) $i->name,
                'words' => $aliases->get($i->id, collect())
                    ->map(fn ($a) => SearchMatch::from((string) $a->alias)?->text)
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->all();
    }
}
