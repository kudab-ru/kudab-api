<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Http\Controllers\Api\Web\CommunitiesController;
use App\Http\Controllers\Api\Web\InterestsController;
use App\Http\Controllers\Api\Web\VenuesController;
use App\Models\City;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * «Что сейчас в афише» для админки: события впереди, публикации, темы, источники и площадки.
 * Площадки, источники и темы берём у тех же ручек, что кормят сайт, иначе числа разойдутся
 * с его страницами. События считаем мимо кэша ленты: после «Обновить» нужно свежее число.
 */
final class AfishaNow
{
    public const KEY = 'admin:afisha-now';

    private const THEMES = 12;

    public function __construct(private readonly EventService $events) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        return Cache::get(self::KEY) ?? $this->rebuild();
    }

    /** @return array<string, mixed> */
    public function rebuild(): array
    {
        $snapshot = $this->build();
        Cache::forever(self::KEY, $snapshot);

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $city = City::query()
            ->where('status', 'active')
            ->orderByRaw("slug = 'voronezh' desc")
            ->orderBy('id')
            ->first(['id', 'slug', 'name']);

        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'city' => $city ? ['slug' => $city->slug, 'name' => $city->name] : null,
            'events_ahead' => 0,
            'posts' => 0,
            'added_24h' => 0,
            'sources' => $this->total(app(CommunitiesController::class)->index(self::request(['per_page' => 1]))),
            'venues' => 0,
            'themes' => [],
        ];
        if ($city === null) {
            return $snapshot;
        }

        $notStarted = now('Europe/Moscow')->startOfMinute()->toDateTimeString();
        $ahead = $this->events->listWeb(['city_id' => $city->id, 'grouped_by_post' => true, 'date_from' => $notStarted], 1);
        $added = $this->events->listWeb([
            'city_id' => $city->id,
            'date_from' => $notStarted,
            'created_from' => now()->subDay()->startOfHour()->toDateTimeString(),
        ], 1);

        $snapshot['events_ahead'] = $ahead['totalEvents'] ?? $ahead['page']->total();
        $snapshot['posts'] = $ahead['page']->total();
        $snapshot['added_24h'] = $added['page']->total();
        $snapshot['venues'] = $this->total(app(VenuesController::class)->index(self::request(['city' => $city->slug, 'per_page' => 1])));
        $snapshot['themes'] = $this->themes(app(InterestsController::class)->index(self::request(['city' => $city->slug])));

        return $snapshot;
    }

    /**
     * Число темы — она сама плюс её жанры: часть событий висит только на жанре («рок» внутри «музыки»).
     *
     * @return list<array{slug: string, name: string, count: int}>
     */
    private function themes(JsonResponse $response): array
    {
        $all = collect($response->getData(true)['data'] ?? []);
        $leaves = $all->whereNotNull('parent_slug')->groupBy('parent_slug');

        return $all->whereNull('parent_id')
            ->map(fn (array $t) => [
                'slug' => (string) $t['slug'],
                'name' => (string) $t['name'],
                'count' => (int) $t['events_count'] + (int) collect($leaves->get($t['slug'], []))->sum('events_count'),
            ])
            ->filter(fn (array $t) => $t['count'] > 0)
            ->sortByDesc('count')
            ->take(self::THEMES)
            ->values()
            ->all();
    }

    private function total(JsonResponse $response): int
    {
        return (int) ($response->getData(true)['meta']['total'] ?? 0);
    }

    private static function request(array $query): Request
    {
        return Request::create('/', 'GET', $query);
    }
}
