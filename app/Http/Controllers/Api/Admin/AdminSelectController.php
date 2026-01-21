<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Select\SelectCitiesRequest;
use App\Http\Requests\Admin\Select\SelectCommunitiesRequest;
use App\Http\Requests\Admin\Select\SelectInterestsRequest;
use App\Models\City;
use App\Models\Community;
use App\Models\Interest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AdminSelectController extends Controller
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    // preload иногда нужно вернуть больше, чем MAX_LIMIT (если ids длиннее)
    private const MAX_PRELOAD_LIMIT = 200;

    public function cities(SelectCitiesRequest $request): JsonResponse
    {
        $v = $request->validated();

        $limit = $this->normalizeLimit($v['limit'] ?? null);
        $ids   = $this->extractIds($v);
        $term  = $this->normalizeTerm($v['q'] ?? null);

        $query = City::query()->select(['id', 'name']);

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
            $this->applyIdsOrder($query, $ids);

            $limit = $this->normalizePreloadLimit($limit, count($ids));
        } elseif ($term !== null) {
            $this->whereILike($query, 'name', $term);
            $query->orderBy('name', 'asc');
        } else {
            $query->orderBy('name', 'asc');
        }

        return response()->json([
            'data' => $query->limit($limit)->get()->map(fn (City $c) => [
                'id'    => (int) $c->id,
                'label' => (string) $c->name,
            ])->values()->all(),
        ]);
    }

    public function communities(SelectCommunitiesRequest $request): JsonResponse
    {
        $v = $request->validated();

        $limit = $this->normalizeLimit($v['limit'] ?? null);
        $ids   = $this->extractIds($v);
        $term  = $this->normalizeTerm($v['q'] ?? null);

        $withDeleted = (bool) ($v['with_deleted'] ?? false);
        $onlyDeleted = (bool) ($v['only_deleted'] ?? false);

        $query = Community::query()
            ->select(['id', 'name', 'city_id', 'deleted_at'])
            ->with(['city:id,name']);

        // preload: всегда вернуть выбранные, даже если deleted
        if (!empty($ids)) {
            $query->withTrashed();
            $onlyDeleted = false; // preload не должен “случайно” отфильтровать живые
        } elseif ($withDeleted || $onlyDeleted) {
            $query->withTrashed();
        }

        if ($onlyDeleted) {
            $query->onlyTrashed();
        }

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
            $this->applyIdsOrder($query, $ids);

            $limit = $this->normalizePreloadLimit($limit, count($ids));
        } else {
            // city_id применяем только в режиме поиска (НЕ preload)
            if (!empty($v['city_id'])) {
                $query->where('city_id', (int) $v['city_id']);
            }

            if ($term !== null) {
                $query->where(function (Builder $w) use ($term) {
                    $this->whereILike($w, 'name', $term);
                    $w->orWhere(function (Builder $ww) use ($term) {
                        $this->whereILike($ww, 'description', $term);
                    });
                });
            }

            $query->orderBy('name', 'asc');
        }

        return response()->json([
            'data' => $query->limit($limit)->get()->map(function (Community $c) {
                $label = (string) $c->name;

                if ($c->relationLoaded('city') && $c->city) {
                    $cityName = (string) $c->city->name;

                    // добавляем город только если его еще нет в названии
                    if ($cityName !== '' && mb_stripos($label, $cityName) === false) {
                        $label .= ' · ' . $cityName;
                    }
                }

                if ($c->deleted_at !== null) {
                    $label .= ' (deleted)';
                }

                return [
                    'id'    => (int) $c->id,
                    'label' => $label,
                ];
            })->values()->all(),
        ]);
    }

    public function interests(SelectInterestsRequest $request): JsonResponse
    {
        $v = $request->validated();

        $limit = $this->normalizeLimit($v['limit'] ?? null);
        $ids   = $this->extractIds($v);
        $term  = $this->normalizeTerm($v['q'] ?? null);

        $query = Interest::query()->select(['id', 'name']);

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
            $this->applyIdsOrder($query, $ids);

            $limit = $this->normalizePreloadLimit($limit, count($ids));
        } elseif ($term !== null) {
            $this->whereILike($query, 'name', $term);
            $query->orderBy('name', 'asc');
        } else {
            $query->orderBy('name', 'asc');
        }

        return response()->json([
            'data' => $query->limit($limit)->get()->map(fn (Interest $i) => [
                'id'    => (int) $i->id,
                'label' => (string) $i->name,
            ])->values()->all(),
        ]);
    }

    private function normalizeLimit(mixed $limit): int
    {
        $n = (int) ($limit ?? self::DEFAULT_LIMIT);
        return max(1, min($n, self::MAX_LIMIT));
    }

    private function normalizePreloadLimit(int $limit, int $idsCount): int
    {
        $limit = max($limit, $idsCount);
        return min($limit, self::MAX_PRELOAD_LIMIT);
    }

    /**
     * Поддержка preload в обоих форматах:
     * - ids[]=1&ids[]=2
     * - ids=1,2
     * - id=5 (если ids не задан)
     */
    private function extractIds(array $validated): array
    {
        $ids = [];

        if (!empty($validated['ids'])) {
            $raw = $validated['ids'];

            if (is_string($raw)) {
                $parts = preg_split('/[,\s]+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
                $ids = array_map('intval', $parts ?: []);
            } elseif (is_array($raw)) {
                $ids = array_map('intval', $raw);
            }
        } elseif (!empty($validated['id'])) {
            $ids = [(int) $validated['id']];
        }

        $ids = array_values(array_filter($ids, fn (int $v) => $v > 0));

        // уникализируем без потери порядка
        $seen = [];
        $unique = [];
        foreach ($ids as $id) {
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $unique[] = $id;
            }
        }

        return $unique;
    }

    /**
     * ORDER BY по заданному списку ids (с сохранением порядка).
     * Важно: сбрасываем существующий ORDER BY, чтобы ids-порядок был главным.
     */
    private function applyIdsOrder(Builder $query, array $ids): void
    {
        if (empty($ids)) return;

        $query->reorder();

        // CASE WHEN id=? THEN pos ... END
        $case = 'CASE';
        $bindings = [];
        foreach ($ids as $pos => $id) {
            $case .= ' WHEN id = ? THEN ?';
            $bindings[] = (int) $id;
            $bindings[] = (int) $pos;
        }
        $case .= ' ELSE ? END';
        $bindings[] = count($ids);

        $query->orderByRaw($case, $bindings);
    }

    /**
     * Нормализуем строку поиска под ILIKE:
     * - trim
     * - экранируем спец-символы LIKE (% _)
     * - используем ESCAPE '!' (это 1 символ и не ломает Postgres)
     */
    private function normalizeTerm(mixed $q): ?string
    {
        if (!is_string($q)) return null;

        $q = trim($q);
        if ($q === '') return null;

        // escape for LIKE with ESCAPE '!'
        $q = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);

        return '%' . $q . '%';
    }

    private function whereILike(Builder $q, string $column, string $pattern): void
    {
        $q->whereRaw("$column ILIKE ? ESCAPE '!'", [$pattern]);
    }
}
