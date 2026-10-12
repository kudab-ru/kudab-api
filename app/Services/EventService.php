<?php

namespace App\Services;

use App\Models\Event;
use App\Repositories\EventRepository;
use App\Services\Recommendations\NextEventsPicker;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class EventService
{
    public function __construct(
        private readonly EventRepository $repo,
        private readonly NextEventsPicker $picker,
    ) {}

    /**
     * Блоки «что дальше» на странице события. Пустые блоки — штатно.
     *
     * @return array{day: ?string, same_day: EloquentCollection, companions: EloquentCollection, similar: EloquentCollection, companions_meta: ?array<string, mixed>}
     */
    public function nextWeb(int $id): array
    {
        $pool = $this->repo->nextPool($id);
        $picked = $pool['base'] === null
            ? ['day' => null, 'same_day' => [], 'companions' => [], 'similar' => []]
            : $this->picker->pick($pool['base'], $pool['items'], $pool['today'], $pool['scope']);

        $sections = [];
        foreach (['same_day', 'companions', 'similar'] as $name) {
            $list = new EloquentCollection;
            foreach ($picked[$name] as $p) {
                $e = $pool['events'][$p['id']];
                $e->setAttribute('rec_reason', $p['reason']);
                $list->push($e);
            }
            $sections[$name] = $list;
        }
        // В «В этот день» карточка должна показывать этот день, а не ближайший день своей серии.
        $this->repo->hydrateRail($sections['same_day'], false);
        $this->repo->hydrateRail($sections['companions']->concat($sections['similar']));

        return $sections + [
            'day' => $picked['day'],
            'companions_meta' => $pool['companions_meta'],
        ];
    }

    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repo->paginateUpcoming($filters, $perPage);
    }

    /**
     * @return array{page: LengthAwarePaginator, totalEvents: int|null}
     */
    public function listWeb(array $filters, int $perPage = 20): array
    {
        return $this->repo->paginateUpcomingWeb($filters, $perPage);
    }

    /** «Недавно было» в пустом поиске, см. EventRepository::listSearchPast. */
    public function listSearchPast(array $filters, int $limit = 5): EloquentCollection
    {
        return $this->repo->listSearchPast($filters, $limit);
    }

    /**
     * Лента прошедших событий площадки («Здесь уже проходило») — all-time,
     * в обход lookback-окна ленты. См. EventRepository::listVenuePast.
     *
     * @return array{page: LengthAwarePaginator, totalEvents: int}
     */
    public function listVenuePast(int $venueId, int $perPage = 24, int $page = 1): array
    {
        return $this->repo->listVenuePast($venueId, $perPage, $page);
    }

    /**
     * @return array{event: Event|null, total: int}
     */
    public function pickRandomWeb(array $filters): array
    {
        return $this->repo->pickRandomWeb($filters);
    }

    public function get(int $id): Event
    {
        return $this->repo->findWithDetails($id);
    }

    public function getWeb(int $id): Event
    {
        return $this->repo->findWebWithDetails($id);
    }

    /**
     * Web: похожие события по интересам (Interests Этап 3).
     * Пустая коллекция — штатно (фронт показывает фолбэк города).
     */
    public function relatedWeb(
        int $id,
        int $limit = 8,
        bool $grouped = false,
        bool $groupedByPost = false
    ): EloquentCollection {
        return $this->repo->relatedByInterests($id, $limit, $grouped, $groupedByPost);
    }

    /**
     * Web: «соседи» события по площадке/организатору (venue-first, иначе community).
     * Отдельная ось от relatedWeb (интересы). Возвращает items + meta (scope/label/
     * venue/community_name) — контроллер раскладывает в data+meta. Пустой items —
     * штатно (фронт скрывает рейл).
     *
     * @return array{items: EloquentCollection, scope: 'venue'|'community'|null, label: ?string, venue: ?array, community_name: ?string}
     */
    public function companionsWeb(
        int $id,
        int $limit = 10,
        bool $grouped = false,
        bool $groupedByPost = false
    ): array {
        return $this->repo->companions($id, $limit, $grouped, $groupedByPost);
    }

    /**
     * Web: получить группу целиком (ленивая подгрузка для карусели).
     * Возвращаем items в том же формате, что /web/events (через WebEventResource на контроллере).
     */
    public function getWebGroup(int $groupId, int $limit = 30): array
    {
        $limit = max(1, min($limit, 50));

        $count = $this->repo->countWebGroup($groupId);
        if ($count <= 0) {
            abort(404);
        }

        $items = $this->repo->listWebGroup($groupId, $limit);
        return ['count' => $count, 'items' => $items];
    }
}
