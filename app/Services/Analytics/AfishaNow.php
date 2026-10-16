<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Http\Resources\WebEventResource;
use App\Models\City;
use App\Models\Event;
use App\Repositories\EventRepository;
use App\Services\EventService;
use App\Services\Telegram\Scoring\EventBroadcastScorer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * «Что сейчас в афише» для админки. Записи берём у ленты сайта, мимо её кэша: после «Обновить»
 * нужно свежее число. Общая лента прячет детские события, поэтому к ней добавляем «С детьми».
 */
final class AfishaNow
{
    /** Меняется вместе с формой снимка: иначе страница получит из кэша старую форму. */
    public const KEY = 'admin:afisha-now:v2';

    /** По одному событию на день недели вперёд. */
    private const TOP_DAYS = 7;

    public function __construct(
        private readonly EventService $events,
        private readonly EventRepository $repository,
        private readonly EventBroadcastScorer $scorer,
    ) {}

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

        $now = CarbonImmutable::now('Europe/Moscow')->startOfMinute();
        $ids = $city ? $this->visibleIds((int) $city->id, $now) : [];

        return [
            'generated_at' => now()->toIso8601String(),
            'city' => $city ? ['slug' => $city->slug, 'name' => $city->name] : null,
            'top' => $this->top($ids, $now),
        ] + (new AfishaBreakdown($ids, $now))->toArray();
    }

    /** @return list<int> */
    private function visibleIds(int $cityId, CarbonImmutable $now): array
    {
        $filters = ['city_id' => $cityId, 'grouped_by_post' => true, 'date_from' => $now->toDateTimeString()];
        $main = $this->events->listWeb($filters, 1)['eventIds'] ?? [];
        $kids = $this->events->listWeb($filters + ['kids' => true], 1)['eventIds'] ?? [];

        return array_values(array_unique(array_merge($main, $kids)));
    }

    /**
     * Самое интересное событие каждого из семи дней по той же оценке, которой автомат выбирает посты канала.
     * Оценка ленты сайта сюда не годится: она мерит полноту карточки, а не интерес.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, title: string, date: string, start_at: ?string, place: ?string, image: ?string}>
     */
    private function top(array $ids, CarbonImmutable $now): array
    {
        $days = array_map(fn (int $i) => $now->addDays($i)->toDateString(), range(0, self::TOP_DAYS - 1));
        $events = Event::query()
            ->whereIn('id', $ids)
            ->whereIn('start_date', $days)
            ->with('venue:id,slug,name,kind')
            ->get();
        if ($events->isEmpty()) {
            return [];
        }

        $this->scorer->prepare($events);
        $ranked = $events
            ->map(fn (Event $e) => ['event' => $e, 'interest' => $this->scorer->interest($e)])
            ->sort(fn (array $a, array $b) => [$b['interest'], $a['event']->start_time?->getTimestamp() ?? PHP_INT_MAX, $a['event']->id]
                <=> [$a['interest'], $b['event']->start_time?->getTimestamp() ?? PHP_INT_MAX, $b['event']->id]);

        // спектакль, который идёт и в субботу, и в воскресенье, показываем один раз
        $picked = new EloquentCollection;
        $seen = [];
        foreach ($days as $day) {
            $best = $ranked->first(fn (array $r) => $r['event']->start_date?->toDateString() === $day
                && ! isset($seen[$r['event']->event_group_id ?? -$r['event']->id]));
            if ($best !== null) {
                $seen[$best['event']->event_group_id ?? -$best['event']->id] = true;
                $picked->push($best['event']);
            }
        }

        $this->repository->hydrateImagesFor($picked);

        return array_map(fn (array $e) => [
            'id' => (int) $e['id'],
            'title' => (string) $e['title'],
            'date' => (string) $e['start_date'],
            'start_at' => $e['start_at'] ?? null,
            'place' => $e['venue']['name'] ?? null,
            'image' => $e['images'][0] ?? $e['poster'] ?? null,
        ], WebEventResource::collection($picked)->toArray(Request::create('/')));
    }
}
