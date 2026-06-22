<?php

namespace App\Repositories;

use Illuminate\Support\Str;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

class EventRepository
{
    private const FUZZY_MIN_LEN = 3;
    private const PAST_GRACE_HOURS = 1;
    /**
     * Окно «event ещё актуален» для лент и счётчиков: события могут отображаться
     * до N дней после старта (юзер видит то, что началось час назад). Public —
     * чтобы /api/web/interests events_count считал в том же окне что
     * /api/web/events; иначе chip-counter «(39)» не совпадёт с числом
     * events в ленте после клика.
     */
    public const PAST_LOOKBACK_DAYS = 7;

    private ?bool $hasTrgm = null;
    private ?bool $hasWordSim = null;

    public function listWebIdsForSitemap(int $afterId = 0, int $limit = 5000, string $mode = 'upcoming'): array
    {
        $limit = max(1, min($limit, 50000));

        $q = Event::query()
            ->select(['id', 'updated_at', 'created_at', 'start_time', 'start_date'])
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->where('ct.status', 'active')
            ->whereNull('events.deleted_at')
            ->where('id', '>', $afterId)
            ->orderBy('id', 'asc')
            ->limit($limit + 1);

        if ($mode !== 'all') {
            $todayMsk = now('Europe/Moscow')->toDateString();

            $q->where(function ($w) use ($todayMsk) {
                $w->where('start_time', '>=', now()->subDay())
                    ->orWhere(function ($x) use ($todayMsk) {
                        $x->whereNull('start_time')
                            ->whereNotNull('start_date')
                            ->where('start_date', '>=', $todayMsk);
                    });
            });
        }

        $this->excludeBlacklistedSources($q);

        $rows = $q->get();

        $hasMore = $rows->count() > $limit;
        if ($hasMore) $rows = $rows->slice(0, $limit);

        $items = $rows->map(function ($e) {
            $lm = $e->updated_at ?? $e->created_at;

            return [
                'id' => (int) $e->id,
                'lastmod' => $lm ? $lm->toAtomString() : null,
            ];
        })->values()->all();

        $nextAfterId = null;
        if ($hasMore && !empty($items)) {
            $nextAfterId = $items[count($items) - 1]['id'];
        }

        return [
            'items' => $items,
            'next_after_id' => $nextAfterId,
        ];
    }

    /**
     * Federation-ключ группы: COALESCE(federation_id, id). Если группа не
     * федерирована (federation_id NULL) — возвращает сам $groupId (no-op).
     * Позволяет /web/event-groups/{id} отдавать события ВСЕЙ федерации.
     */
    private function federationKeyOf(int $groupId): int
    {
        $fk = DB::table('event_groups')
            ->where('id', $groupId)
            ->selectRaw('COALESCE(federation_id, id) as fk')
            ->value('fk');

        return $fk !== null ? (int) $fk : $groupId;
    }

    /**
     * Web: количество событий в группе (для "count" в ответе).
     * Считаем по тем же правилам, что /web/events (active city + not deleted + not blacklisted + lookback window).
     * Federation-aware: считает по всей федерации запрошенной группы.
     */
    public function countWebGroup(int $groupId): int
    {
        $fedKey = $this->federationKeyOf($groupId);

        $nowMsk = now('Europe/Moscow');
        $fromDateMsk = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS)->toDateString();
        $cutoffTs = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS);

        $q = Event::query()
            ->join('event_groups as eg', 'eg.id', '=', 'events.event_group_id')
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->whereNull('eg.deleted_at')
            ->where('ct.status', 'active')
            ->whereNull('events.deleted_at')
            ->whereRaw('COALESCE(eg.federation_id, eg.id) = ?', [$fedKey])
            ->where(function ($w) use ($cutoffTs, $fromDateMsk) {
                $w->where('events.start_time', '>=', $cutoffTs)
                    ->orWhere(function ($x) use ($fromDateMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDateMsk);
                    });
            });

        $this->excludeBlacklistedSources($q);

        return (int) $q->count('events.id');
    }

    /**
     * Web: события конкретной группы (ленивая подгрузка карусели).
     * Важно: формат полей + poster/images соответствует /web/events (hydrateImages + __is_past).
     * Фильтры: как /web/events (active city + not deleted + not blacklisted + lookback window).
     */
    public function listWebGroup(int $groupId, int $limit = 30): EloquentCollection
    {
        $limit = max(1, min($limit, 50));

        $fedKey = $this->federationKeyOf($groupId);

        $nowMsk = now('Europe/Moscow');
        $fromDateMsk = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS)->toDateString();
        $cutoffTs = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS);

        $q = Event::query()
            ->select('events.*', 'ct.slug as city_slug')
            ->join('event_groups as eg', 'eg.id', '=', 'events.event_group_id')
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->whereNull('eg.deleted_at')
            ->where('ct.status', 'active')
            ->whereNull('events.deleted_at')
            ->whereRaw('COALESCE(eg.federation_id, eg.id) = ?', [$fedKey])
            ->where(function ($w) use ($cutoffTs, $fromDateMsk) {
                $w->where('events.start_time', '>=', $cutoffTs)
                    ->orWhere(function ($x) use ($fromDateMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDateMsk);
                    });
            });

        $this->addPastFlags($q); // __past_rank + __is_past
        $this->addGrayRank($q);
        $this->addImgRank($q);
        $this->excludeBlacklistedSources($q);

        $q->orderByRaw('events.start_date asc nulls last')
            ->orderByRaw('events.start_time asc nulls last')
            ->orderBy('events.id', 'asc')
            ->limit($limit);

        $items = $q->get();
        $this->hydrateImages($items);

        $items->each(function (Event $e) {
            $e->makeHidden(['__past_rank', '__is_past', '__gray_rank', '__img_rank']);
        });

        return $items;
    }

    public function paginateUpcoming(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $nowMsk = now('Europe/Moscow');
        $fromDateMsk = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS)->toDateString();
        $cutoffTs = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS);

        $q = Event::query()
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->where('ct.status', 'active')
            ->whereNull('events.deleted_at')
            ->where(function ($w) use ($cutoffTs, $fromDateMsk) {
                $w->where('events.start_time', '>=', $cutoffTs)
                    ->orWhere(function ($x) use ($fromDateMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDateMsk);
                    });
            })
            ->with([
                'community:id,name,city,avatar_url',
                'interests:id,name',
                'venue:id,slug,name,kind',
            ]);

        $q->addSelect('events.*');
        $this->addPastFlags($q);
        $this->addGrayRank($q);
        $this->addImgRank($q);

        $this->excludeBlacklistedSources($q);

        $onlyActual = array_key_exists('only_actual', $filters)
            ? (filter_var($filters['only_actual'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true)
            : false;

        if ($onlyActual) {
            $this->applyOnlyActual($q);
        }

        // приоритет: past -> image -> gray
        $q->orderBy('__past_rank', 'asc')
            ->orderBy('__img_rank', 'asc')
            ->orderBy('__gray_rank', 'asc')
            ->orderByRaw('events.start_date asc nulls last')
            ->orderByRaw('events.start_time asc nulls last')
            ->orderBy('events.id', 'asc');

        if (!empty($filters['city_id'])) {
            $q->where('events.city_id', (int) $filters['city_id']);
        } elseif (!empty($filters['city'])) {
            $q->where('events.city', 'ILIKE', trim((string) $filters['city']));
        }

        if (!empty($filters['date_from'])) {
            $fromDate = substr((string) $filters['date_from'], 0, 10);

            $q->where(function ($w) use ($filters, $fromDate) {
                $w->where('events.start_time', '>=', $filters['date_from'])
                    ->orWhere(function ($x) use ($fromDate) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDate);
                    });
            });
        }

        if (!empty($filters['date_to'])) {
            $toDate = substr((string) $filters['date_to'], 0, 10);

            $q->where(function ($w) use ($filters, $toDate) {
                $w->where('events.start_time', '<=', $filters['date_to'])
                    ->orWhere(function ($x) use ($toDate) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '<=', $toDate);
                    });
            });
        }

        if (!empty($filters['community_id'])) {
            $q->where('events.community_id', (int) $filters['community_id']);
        }

        if (!empty($filters['venue_id'])) {
            $q->where('events.venue_id', (int) $filters['venue_id']);
        }

        $qNorm = $this->normalizeQ($filters['q'] ?? null);

        if ($qNorm !== null) {
            $q->leftJoin('communities as cm', 'cm.id', '=', 'events.community_id')
                ->distinct();

            $like = '%'.$qNorm.'%';

            $token = $this->pickFuzzyToken($qNorm);
            $thr = $this->fuzzyThreshold($token);

            $fuzzyOn = $this->trgmEnabled()
                && $this->wordSimEnabled()
                && mb_strlen($token) >= self::FUZZY_MIN_LEN
                && mb_strlen($token) >= 4;

            $q->where(function ($w) use ($like, $token, $thr, $fuzzyOn) {
                $w->whereRaw("public.ru_normalize(events.title) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(events.description) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.name) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.description) LIKE ?", [$like]);

                if ($fuzzyOn) {
                    $w->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(events.title)) >= ?",
                        [$token, $thr]
                    )->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(cm.name)) >= ?",
                        [$token, $thr]
                    );
                }
            });

            if (empty($filters['sort']) && $fuzzyOn) {
                $isLikeExpr = "CASE WHEN (
                public.ru_normalize(events.title) LIKE ?
                OR public.ru_normalize(cm.name) LIKE ?
            ) THEN 0 ELSE 1 END";

                $scoreExpr = "GREATEST(
                word_similarity(?, public.ru_normalize(events.title)),
                word_similarity(?, public.ru_normalize(cm.name))
            )";

                $q->selectRaw("$isLikeExpr as __like_rank", [$like, $like]);
                $q->selectRaw("$scoreExpr as __score", [$token, $token]);

                $q->reorder()
                    ->orderBy('__past_rank', 'asc')
                    ->orderBy('__img_rank', 'asc')
                    ->orderBy('__gray_rank', 'asc')
                    ->orderBy('__like_rank', 'asc')
                    ->orderBy('__score', 'desc')
                    ->orderByRaw('events.start_date asc nulls last')
                    ->orderByRaw('events.start_time asc nulls last')
                    ->orderBy('events.id', 'asc');
            }
        }

        if (!empty($filters['interests']) && is_array($filters['interests'])) {
            $ids = array_filter(array_map('intval', $filters['interests']));
            if ($ids) {
                $q->whereHas('interests', function ($w) use ($ids) {
                    $w->whereIn('interests.id', $ids);
                });
            }
        }

        $paginator = $q->paginate($perPage);
        $events = $paginator->getCollection();
        $this->hydrateImages($events);

        $events->each(function (Event $e) {
            $e->makeHidden(['__past_rank', '__is_past', '__gray_rank', '__img_rank', '__like_rank', '__score', '__unknown_last']);
        });

        return $paginator->setCollection($events);
    }

    /**
     * @return array{page: LengthAwarePaginator, totalEvents: int|null}
     *   - page: пагинатор reps (используется для hasMore-логики на фронте)
     *   - totalEvents: количество events до схлопывания (для display-счётчика),
     *     либо null если ни grouped, ни grouped_by_post не были запрошены
     */
    /**
     * Double-write на время прод-rollout Этапа 2: фронт постепенно мигрирует
     * с interests[]=ID на interests[]=slug. Validator не пускает mixed, так
     * что массив гарантированно гомогенный.
     *
     * - all numeric → legacy путь, прямой whereIn(id), без иерархии (как было
     *   до Этапа 2: parent не разворачивался — сохраняем семантику).
     * - all string → новый путь через recursive CTE.
     *
     * Cleanup-PR через 1-2 недели после миграции фронта снесёт legacy-ветку.
     *
     * @param array<int|string> $input
     * @return int[]
     */
    private function resolveInterestFilterIds(array $input): array
    {
        if (!$input) return [];

        $first = reset($input);
        $isLegacyInt = is_int($first) || (is_string($first) && ctype_digit($first));

        if ($isLegacyInt) {
            return array_values(array_filter(array_map('intval', $input)));
        }

        $strings = array_values(array_filter($input, 'is_string'));
        return $this->expandInterestSlugsToIds($strings);
    }

    /**
     * Раскрывает interest-slugs до полного set id (self + все потомки через
     * recursive CTE). Используется только web-методами; bot/admin продолжают
     * принимать interest int[].
     *
     * Опечатка в slug → empty result у вызывающего (по плану — это фича, не баг).
     *
     * @param string[] $slugs
     * @return int[]
     */
    private function expandInterestSlugsToIds(array $slugs): array
    {
        $norm = [];
        foreach ($slugs as $s) {
            if (!is_string($s)) continue;
            $s = mb_strtolower(trim($s));
            if ($s === '') continue;
            $norm[] = $s;
        }
        if (!$norm) return [];

        // slugs валидированы regex [a-z0-9-]+ в EventsController → запятой
        // быть не может, безопасно склеить в CSV для string_to_array.
        $csv = implode(',', array_values(array_unique($norm)));

        $rows = DB::select(
            "WITH RECURSIVE picked AS (
                SELECT id FROM interests WHERE slug = ANY(string_to_array(?, ','))
                UNION
                SELECT i.id FROM interests i JOIN picked p ON i.parent_id = p.id
            )
            SELECT id FROM picked",
            [$csv]
        );

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    public function paginateUpcomingWeb(array $filters, int $perPage = 20): array
    {
        $grouped = array_key_exists('grouped', $filters)
            ? (filter_var($filters['grouped'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true)
            : false;

        // grouped_by_post: ось B группировки (kudab-parser/TASKS.md 2.3).
        // Один пост в соцсети рождает несколько разных events — кластеризуем
        // по event_sources(source, post_external_id), оставляем одного
        // представителя на кластер, остальных отдаём в payload как siblings.
        $groupedByPost = array_key_exists('grouped_by_post', $filters)
            ? (filter_var($filters['grouped_by_post'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true)
            : false;

        // sort=top ОБЯЗАН схлопывать одинаковые события в одну карточку, как grouped=1.
        // v2-главная (hero / «Куда на выходных» / «Событие дня») шлёт sort=top БЕЗ grouped=1,
        // а ранжирование без группировки выдаёт каждый сеанс/групп-дубль отдельной карточкой
        // (баг «задвоенной ленты» после выкатки ранжирования). Axis A (event_group_id) ниже
        // включается для grouped ИЛИ sort=top; с grouped_by_post (axis B) композируется.
        $sortTop = (($filters['sort'] ?? null) === 'top');
        $collapseGroups = $grouped || $sortTop;

        $nowMsk = now('Europe/Moscow');
        $fromDateMsk = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS)->toDateString();
        $cutoffTs = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS);

        $q = Event::query()
            ->select('events.*', 'ct.slug as city_slug')
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->where('ct.status', 'active')
            ->whereNull('events.deleted_at')
            ->where(function ($w) use ($cutoffTs, $fromDateMsk) {
                $w->where('events.start_time', '>=', $cutoffTs)
                    ->orWhere(function ($x) use ($fromDateMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDateMsk);
                    });
            })
            ->with(['interests:id,slug,name']);

        $this->addPastFlags($q); // __past_rank + __is_past
        $this->addGrayRank($q);
        $this->addImgRank($q);

        $this->excludeBlacklistedSources($q);
        $this->applyMainFeedTaxonomyFilter($q, $filters);

        if ($sortTop) {
            // Ранжированная лента: будущее первее, внутри — по «интересности»
            // (__top_score, порт EventBroadcastScorer). Группы схлопываются ниже
            // через $collapseGroups (axis A) — представитель остаётся канонический,
            // а ORDER BY __top_score ранжирует уже представителей групп.
            $this->addTopScore($q);
            $q->orderBy('__past_rank', 'asc')
                ->orderByRaw('__top_score desc')
                ->orderByRaw('events.start_date asc nulls last')
                ->orderByRaw('events.start_time asc nulls last')
                ->orderBy('events.id', 'asc');
        } else {
            // По умолчанию — хронология: past -> image -> gray -> дата
            $q->orderBy('__past_rank', 'asc')
                ->orderBy('__img_rank', 'asc')
                ->orderBy('__gray_rank', 'asc')
                ->orderByRaw('events.start_date asc nulls last')
                ->orderByRaw('events.start_time asc nulls last')
                ->orderBy('events.id', 'asc');
        }

        if (!empty($filters['city_id'])) {
            $q->where('events.city_id', (int) $filters['city_id']);
        }

        if (!empty($filters['date_from'])) {
            $fromDate = substr((string) $filters['date_from'], 0, 10);

            $q->where(function ($w) use ($filters, $fromDate) {
                $w->where('events.start_time', '>=', $filters['date_from'])
                    ->orWhere(function ($x) use ($fromDate) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDate);
                    });
            });
        }

        if (!empty($filters['date_to'])) {
            $toDate = substr((string) $filters['date_to'], 0, 10);

            $q->where(function ($w) use ($filters, $toDate) {
                $w->where('events.start_time', '<=', $filters['date_to'])
                    ->orWhere(function ($x) use ($toDate) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '<=', $toDate);
                    });
            });
        }

        if (!empty($filters['community_id'])) {
            $q->where('events.community_id', (int) $filters['community_id']);
        }

        if (!empty($filters['venue_id'])) {
            $q->where('events.venue_id', (int) $filters['venue_id']);
        }

        $qNorm = $this->normalizeQ($filters['q'] ?? null);
        $like = null;
        $token = null;
        $thr = null;
        $fuzzyOn = false;
        $hasDistinct = false;

        if ($qNorm !== null) {
            $q->leftJoin('communities as cm', 'cm.id', '=', 'events.community_id')
                ->distinct();

            $hasDistinct = true;

            $like = '%'.$qNorm.'%';
            $token = $this->pickFuzzyToken($qNorm);
            $thr = $this->fuzzyThreshold($token);

            $fuzzyOn = $this->trgmEnabled()
                && $this->wordSimEnabled()
                && mb_strlen($token) >= self::FUZZY_MIN_LEN
                && mb_strlen($token) >= 4;

            $q->where(function ($w) use ($like, $token, $thr, $fuzzyOn) {
                $w->whereRaw("public.ru_normalize(events.title) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(events.description) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.name) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.description) LIKE ?", [$like]);

                if ($fuzzyOn) {
                    $w->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(events.title)) >= ?",
                        [$token, $thr]
                    )->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(cm.name)) >= ?",
                        [$token, $thr]
                    );
                }
            });
        }

        if (!empty($filters['interests']) && is_array($filters['interests'])) {
            // Double-write: input — либо int[] (legacy), либо slug[] (Этап 2,
            // CTE разворачивает parent → self+descendants). Validator выше не
            // пускает mixed-array. Пустой ids → 1=0 (защита от typo в slug).
            $ids = $this->resolveInterestFilterIds($filters['interests']);
            if ($ids) {
                $q->whereHas('interests', function ($w) use ($ids) {
                    $w->whereIn('interests.id', $ids);
                });
            } else {
                $q->whereRaw('1 = 0');
            }
        }

        if (!empty($filters['free'])) {
            $q->where(function ($w) {
                $w->where('events.price_status', 'free')
                    ->orWhere(function ($x) {
                        $x->where('events.price_min', 0)
                            ->whereNull('events.price_max');
                    });
            });
        }

        $priceMin = array_key_exists('price_min', $filters) ? (int) $filters['price_min'] : null;
        $priceMax = array_key_exists('price_max', $filters) ? (int) $filters['price_max'] : null;
        $priced = array_key_exists('priced', $filters)
            ? (filter_var($filters['priced'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true)
            : false;

        $hasRange = ($priceMin !== null) || ($priceMax !== null);

        $knownPrice = function ($w) {
            $w->where('events.price_status', 'free')
                ->orWhereNotNull('events.price_min')
                ->orWhereNotNull('events.price_max');
        };

        $unknownCaseSql =
            "CASE WHEN (events.price_min IS NULL AND events.price_max IS NULL AND COALESCE(events.price_status,'') <> 'free') THEN 1 ELSE 0 END";

        $minExpr = "COALESCE(events.price_min, events.price_max, CASE WHEN events.price_status='free' THEN 0 END)";
        $maxExpr = "COALESCE(events.price_max, events.price_min, CASE WHEN events.price_status='free' THEN 0 END)";

        if ($hasRange) {
            $q->where(function ($w) use ($knownPrice, $priced, $minExpr, $maxExpr, $priceMin, $priceMax) {
                $w->where(function ($x) use ($knownPrice, $minExpr, $maxExpr, $priceMin, $priceMax) {
                    $x->where($knownPrice);

                    if ($priceMin !== null) $x->whereRaw("$maxExpr >= ?", [$priceMin]);
                    if ($priceMax !== null) $x->whereRaw("$minExpr <= ?", [$priceMax]);
                });

                if (!$priced) {
                    $w->orWhere(function ($x) {
                        $x->whereNull('events.price_min')
                            ->whereNull('events.price_max')
                            ->whereRaw("COALESCE(events.price_status,'') <> 'free'");
                    });
                }
            });
        } elseif ($priced) {
            $q->where($knownPrice);
        }

        $unknownLast = $hasRange && !$priced;
        if ($unknownLast && $hasDistinct) {
            $q->selectRaw("$unknownCaseSql as __unknown_last");
        }

        if ($unknownLast) {
            $q->reorder()
                ->orderBy('__past_rank', 'asc');

            if ($hasDistinct) $q->orderBy('__unknown_last', 'asc');
            else $q->orderByRaw("$unknownCaseSql asc");

            $q->orderBy('__img_rank', 'asc')
                ->orderBy('__gray_rank', 'asc')
                ->orderByRaw('events.start_date asc nulls last')
                ->orderByRaw('events.start_time asc nulls last')
                ->orderBy('events.id', 'asc');
        }

        if (!empty($filters['tod'])) {
            $tod = (string) $filters['tod'];
            $q->whereNotNull('events.start_time');

            $hourExpr = "EXTRACT(HOUR FROM (events.start_time AT TIME ZONE 'Europe/Moscow'))";

            switch ($tod) {
                case 'morning':
                    $q->whereRaw("{$hourExpr} >= 5 AND {$hourExpr} <= 11");
                    break;
                case 'day':
                    $q->whereRaw("{$hourExpr} >= 12 AND {$hourExpr} <= 16");
                    break;
                case 'evening':
                    $q->whereRaw("{$hourExpr} >= 17 AND {$hourExpr} <= 22");
                    break;
                case 'night':
                    $q->where(function ($w) use ($hourExpr) {
                        $w->whereRaw("{$hourExpr} >= 23")
                            ->orWhereRaw("{$hourExpr} <= 4");
                    });
                    break;
            }
        }

        $sort = $filters['sort'] ?? null;
        $dir = strtolower((string) ($filters['dir'] ?? 'asc'));
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        // sort=top уже получил свой порядок выше (past → __top_score → дата); этот
        // блок (явные sort=start_at/price_min/…) НЕ должен его перетирать через
        // reorder() — иначе ранжирование схлопывается в хронологию (past→img→gray→id).
        if ($sort && $sort !== 'top') {
            $q->reorder()
                ->orderBy('__past_rank', 'asc');

            if ($unknownLast) {
                if ($hasDistinct) $q->orderBy('__unknown_last', 'asc');
                else $q->orderByRaw("$unknownCaseSql asc");
            }

            $q->orderBy('__img_rank', 'asc')
                ->orderBy('__gray_rank', 'asc');

            switch ($sort) {
                case 'start_at':
                case 'start_date':
                    $q->orderByRaw("events.start_date {$dir} nulls last")
                        ->orderByRaw("events.start_time {$dir} nulls last");
                    break;

                case 'start_time':
                    $q->orderByRaw("events.start_time {$dir} nulls last")
                        ->orderByRaw("events.start_date {$dir} nulls last");
                    break;

                case 'created_at':
                    $q->orderBy("events.created_at", $dir);
                    break;

                case 'price_min':
                    $q->orderByRaw("events.price_min {$dir} nulls last");
                    break;
            }

            $q->orderBy('events.id', 'asc');
        }

        if (empty($filters['sort']) && $fuzzyOn && $like !== null && $token !== null) {
            $isLikeExpr = "CASE WHEN (
            public.ru_normalize(events.title) LIKE ?
            OR public.ru_normalize(cm.name) LIKE ?
        ) THEN 0 ELSE 1 END";

            $scoreExpr = "GREATEST(
            word_similarity(?, public.ru_normalize(events.title)),
            word_similarity(?, public.ru_normalize(cm.name))
        )";

            $q->selectRaw("$isLikeExpr as __like_rank", [$like, $like]);
            $q->selectRaw("$scoreExpr as __score", [$token, $token]);

            $q->reorder()
                ->orderBy('__past_rank', 'asc');

            if ($unknownLast) {
                if ($hasDistinct) $q->orderBy('__unknown_last', 'asc');
                else $q->orderByRaw("$unknownCaseSql asc");
            }

            $q->orderBy('__img_rank', 'asc')
                ->orderBy('__gray_rank', 'asc')
                ->orderBy('__like_rank', 'asc')
                ->orderBy('__score', 'desc')
                ->orderByRaw('events.start_date asc nulls last')
                ->orderByRaw('events.start_time asc nulls last')
                ->orderBy('events.id', 'asc');
        }

        if ($collapseGroups) {
            $base = clone $q;

            try {
                $base->reorder();
            } catch (\Throwable $e) {
            }

            $annot = DB::query()
                ->fromSub($base->toBase(), 'b')
                ->select('b.*')
                ->leftJoin('event_groups as eg', function ($j) {
                    $j->on('eg.id', '=', 'b.event_group_id')
                        ->whereNull('eg.deleted_at');
                })
                ->selectRaw("eg.current_event_id as __grp_current_event_id")
                ->selectRaw("eg.federation_id as __grp_federation_id")
                ->selectRaw("
                    COALESCE(
                      b.start_time,
                      (b.start_date AT TIME ZONE 'Europe/Moscow')::timestamp
                    ) as __grp_start_ts
                ")
                ->selectRaw("
                    CASE WHEN (
                      COALESCE(
                        b.start_time,
                        (b.start_date AT TIME ZONE 'Europe/Moscow')::timestamp
                      ) >= (now() AT TIME ZONE 'Europe/Moscow')
                    ) THEN 0 ELSE 1 END as __grp_future_rank
                ");

            $ranked = DB::query()
                ->fromSub($annot, 'b2')
                ->select('b2.*')
                ->selectRaw("
                    row_number() over (
                      partition by COALESCE(b2.__grp_federation_id, b2.event_group_id, -b2.id)
                      order by
                        CASE
                          WHEN b2.__grp_current_event_id IS NOT NULL
                           AND b2.id = b2.__grp_current_event_id
                          THEN 0 ELSE 1
                        END asc,
                        b2.__grp_future_rank asc,
                        case when b2.__grp_future_rank = 0 then b2.__grp_start_ts end asc nulls last,
                        case when b2.__grp_future_rank = 1 then b2.__grp_start_ts end desc nulls last,
                        b2.id asc
                    ) as __grp_rn
                ");

            $repIds = DB::query()
                ->fromSub($ranked, 'r')
                ->select('r.id')
                ->where('r.__grp_rn', 1);

            $q->whereIn('events.id', $repIds);
        }

        // grouped_by_post: ось B. Кластеризуем filtered-events по
        // event_sources(source, post_external_id). Кластер = ≥2 events на
        // одну пару. Rep = earliest start_at в кластере. Соло-events (нет
        // event_sources или кластер size=1) проходят через как обычно
        // (partition by event_id, rn=1 всегда). Фильтры применяются
        // ДО кластеризации — кластер собирается только из подходящих под
        // фильтры events.

        // Снимок $q ПОСЛЕ axis A (grouped), но ДО axis B (grouped_by_post) —
        // используется для подсчёта `total_events`: реальное число events,
        // на которые юзер может перейти (с axis A уже схлопнутыми по
        // event_group_id, потому что разные даты одного события = тот же
        // event detail). `meta.total` остаётся rep-count для пагинации.
        //
        // ВАЖНО: snapshot должен включать те же события что и основной $q
        // (т.е. past+future в окне PAST_LOOKBACK_DAYS). Иначе items на
        // странице (rep'ы из $q + future siblings) могут превысить
        // total_events: past соло-rep'ы попадут в items, но не в total.
        // past siblings отбрасываются позже в hydrateSiblings через
        // siblingIsFuture — это не противоречит, потому что past siblings
        // ВСЁ РАВНО присутствуют в pool eligibleEventIds и учитываются
        // как самостоятельные events на странице.
        $uncollapsedQ = $groupedByPost ? (clone $q) : null;

        if ($groupedByPost) {
            $base = clone $q;
            try { $base->reorder(); } catch (\Throwable $e) {}

            // Step 1: filtered events × LEFT JOIN event_sources
            // (LEFT JOIN — чтобы соло-events без event_sources тоже попали)
            $ep = DB::query()
                ->fromSub(
                    $base->toBase()->select('events.id as event_id', 'events.start_time', 'events.start_date'),
                    'fe'
                )
                ->leftJoin('event_sources as es', 'es.event_id', '=', 'fe.event_id')
                ->select([
                    'fe.event_id',
                    'fe.start_time',
                    'fe.start_date',
                    'es.source',
                    'es.post_external_id',
                ]);

            // Step 2: cluster sizes (только для не-null пар)
            $cs = DB::query()
                ->fromSub($ep, 'cs_ep')
                ->whereNotNull('cs_ep.source')
                ->whereNotNull('cs_ep.post_external_id')
                ->groupBy('cs_ep.source', 'cs_ep.post_external_id')
                ->selectRaw('cs_ep.source, cs_ep.post_external_id, COUNT(DISTINCT cs_ep.event_id) AS cnt');

            // Step 3: ep + cluster_cnt (0 если не в кластере или null pair)
            $epSize = DB::query()
                ->fromSub($ep, 'ep2')
                ->leftJoinSub($cs, 'cs2', function ($j) {
                    $j->on('cs2.source', '=', 'ep2.source')
                        ->on('cs2.post_external_id', '=', 'ep2.post_external_id');
                })
                ->selectRaw("
                    ep2.event_id, ep2.start_time, ep2.start_date,
                    ep2.source, ep2.post_external_id,
                    COALESCE(cs2.cnt, 0) AS cluster_cnt
                ");

            // Step 4: canonical cluster per event (max cnt wins для cross-post)
            $canonTmp = DB::query()
                ->fromSub($epSize, 'es3')
                ->selectRaw("
                    es3.event_id, es3.start_time, es3.start_date,
                    es3.source, es3.post_external_id, es3.cluster_cnt,
                    ROW_NUMBER() OVER (
                        PARTITION BY es3.event_id
                        ORDER BY
                            es3.cluster_cnt DESC NULLS LAST,
                            es3.source ASC NULLS LAST,
                            es3.post_external_id ASC NULLS LAST
                    ) AS canon_rn
                ");

            $canon = DB::query()
                ->fromSub($canonTmp, 'ct')
                ->select(['ct.event_id', 'ct.start_time', 'ct.start_date', 'ct.source', 'ct.post_external_id', 'ct.cluster_cnt'])
                ->where('ct.canon_rn', 1);

            // Step 5: partition by cluster_key (если в кластере ≥2) или
            // 'solo|<event_id>' (иначе). Rank: future-first → earliest
            // start_at. rn=1 — rep кластера (или solo event).
            //
            // future-first (TASKS.md §13p): если в кластере есть хотя бы
            // одно будущее событие — оно становится rep'ом, кластер
            // остаётся в актуальной ленте. Если все события past —
            // earliest past, как раньше (кластер уходит в архив целиком,
            // что корректно). Это исправляет баг «весь кластер уезжает
            // в past из-за одного past sibling».
            $graceHours = (int) self::PAST_GRACE_HOURS;
            $ranked = DB::query()
                ->fromSub($canon, 'c')
                ->selectRaw("
                    c.event_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY
                            CASE
                                WHEN c.cluster_cnt >= 2 THEN c.source || '|' || c.post_external_id
                                ELSE 'solo|' || c.event_id::text
                            END
                        ORDER BY
                            CASE WHEN (
                                (c.start_time IS NOT NULL AND c.start_time < (now() - interval '{$graceHours} hours'))
                                OR (c.start_time IS NULL AND c.start_date IS NOT NULL AND c.start_date < (now() AT TIME ZONE 'Europe/Moscow')::date)
                            ) THEN 1 ELSE 0 END ASC,
                            COALESCE(
                                c.start_time,
                                (c.start_date AT TIME ZONE 'Europe/Moscow')::timestamp
                            ) ASC NULLS LAST,
                            c.event_id ASC
                    ) AS __post_rn
                ");

            $postRepIds = DB::query()
                ->fromSub($ranked, 'r')
                ->select('r.event_id')
                ->where('r.__post_rn', 1);

            $q->whereIn('events.id', $postRepIds);
        }

        $paginator = $q->paginate($perPage);
        $events = $paginator->getCollection();
        $this->hydrateImages($events);

        $events->each(function (Event $e) {
            $e->makeHidden([
                '__past_rank',
                '__is_past',
                '__gray_rank',
                '__img_rank',
                '__like_rank',
                '__score',
                '__top_score',
                '__unknown_last',
            ]);
        });

        if ($collapseGroups) {
            $this->hydrateGroupDates($events);
        }

        $totalEvents = null;
        $eligibleEventIds = null;
        if ($uncollapsedQ !== null) {
            // Snapshot used both для count (`total_events`) и для siblings —
            // гарантирует консистентность: события в siblings — subset тех
            // же, что попали в `total_events`. Без этого siblings могли
            // содержать events из другого города / категории / blacklisted
            // source, и фронтенд показывал «84 из 78» (TASKS.md §14).
            $eligibleEventIds = $uncollapsedQ->toBase()->pluck('events.id')
                ->map(fn ($v) => (int) $v)
                ->all();
            $totalEvents = count($eligibleEventIds);
        }

        if ($groupedByPost) {
            $this->hydrateSiblings($events, $eligibleEventIds);
        }

        // Diversity: в ранжированной ленте не даём >2 карточек одного content_kind
        // подряд (иначе сверху 5 спектаклей + 4 выставки). Пост-сортировка, score
        // сохраняется максимально (жадно сдвигаем только нарушителей).
        if ($sortTop) {
            $events = $this->diversifyByContentKind($events);
        }

        $page = $paginator->setCollection($events);

        return ['page' => $page, 'totalEvents' => $totalEvents];
    }

    /**
     * Random single event для компаса на странице афиши (kudab-frontend).
     *
     * Применяет тот же фильтр-стек что paginateUpcomingWeb, но БЕЗ
     * grouped/grouped_by_post (компас выбирает из всех событий, не из rep'ов)
     * и БЕЗ past-окна для отображения (PAST_LOOKBACK_DAYS) — рандом не должен
     * выдать уже прошедшее событие.
     *
     * @param array{
     *   city_id?: int, date_from?: string, date_to?: string, community_id?: int, venue_id?: int,
     *   q?: string, interests?: array<int|string>, free?: bool, price_min?: int, price_max?: int,
     *   priced?: bool, tod?: string, exclude_ids?: int[]
     * } $filters
     * @return array{event: Event|null, total: int}
     */
    public function pickRandomWeb(array $filters): array
    {
        $excludeIds = [];
        if (!empty($filters['exclude_ids']) && is_array($filters['exclude_ids'])) {
            $excludeIds = array_values(array_filter(array_map('intval', $filters['exclude_ids'])));
        }

        $graceHours = (int) self::PAST_GRACE_HOURS;
        $nowMsk = now('Europe/Moscow');
        $fromDateMsk = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS)->toDateString();
        $cutoffTs = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS);

        $q = Event::query()
            ->select('events.*', 'ct.slug as city_slug')
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->where('ct.status', 'active')
            ->whereNull('events.deleted_at')
            ->where(function ($w) use ($cutoffTs, $fromDateMsk) {
                $w->where('events.start_time', '>=', $cutoffTs)
                    ->orWhere(function ($x) use ($fromDateMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDateMsk);
                    });
            })
            ->with(['interests:id,slug,name']);

        // компас не показывает уже прошедшие события
        $q->whereRaw("NOT (
            (events.start_time IS NOT NULL AND events.start_time < (now() - interval '{$graceHours} hours'))
            OR
            (events.start_time IS NULL AND events.start_date IS NOT NULL AND events.start_date < (now() AT TIME ZONE 'Europe/Moscow')::date)
        )");

        $this->excludeBlacklistedSources($q);
        $this->applyMainFeedTaxonomyFilter($q, $filters);

        if (!empty($filters['city_id'])) {
            $q->where('events.city_id', (int) $filters['city_id']);
        }

        if (!empty($filters['date_from'])) {
            $fromDate = substr((string) $filters['date_from'], 0, 10);
            $q->where(function ($w) use ($filters, $fromDate) {
                $w->where('events.start_time', '>=', $filters['date_from'])
                    ->orWhere(function ($x) use ($fromDate) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDate);
                    });
            });
        }

        if (!empty($filters['date_to'])) {
            $toDate = substr((string) $filters['date_to'], 0, 10);
            $q->where(function ($w) use ($filters, $toDate) {
                $w->where('events.start_time', '<=', $filters['date_to'])
                    ->orWhere(function ($x) use ($toDate) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '<=', $toDate);
                    });
            });
        }

        if (!empty($filters['community_id'])) {
            $q->where('events.community_id', (int) $filters['community_id']);
        }

        if (!empty($filters['venue_id'])) {
            $q->where('events.venue_id', (int) $filters['venue_id']);
        }

        $qNorm = $this->normalizeQ($filters['q'] ?? null);
        if ($qNorm !== null) {
            $like = '%' . $qNorm . '%';
            $token = $this->pickFuzzyToken($qNorm);
            $thr = $this->fuzzyThreshold($token);
            $fuzzyOn = $this->trgmEnabled()
                && $this->wordSimEnabled()
                && mb_strlen($token) >= self::FUZZY_MIN_LEN
                && mb_strlen($token) >= 4;

            $q->leftJoin('communities as cm', 'cm.id', '=', 'events.community_id')->distinct();

            $q->where(function ($w) use ($like, $token, $thr, $fuzzyOn) {
                $w->whereRaw("public.ru_normalize(events.title) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(events.description) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.name) LIKE ?", [$like])
                    ->orWhereRaw("public.ru_normalize(cm.description) LIKE ?", [$like]);
                if ($fuzzyOn) {
                    $w->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(events.title)) >= ?",
                        [$token, $thr]
                    )->orWhereRaw(
                        "word_similarity(?, public.ru_normalize(cm.name)) >= ?",
                        [$token, $thr]
                    );
                }
            });
        }

        if (!empty($filters['interests']) && is_array($filters['interests'])) {
            // Double-write — см. resolveInterestFilterIds().
            $ids = $this->resolveInterestFilterIds($filters['interests']);
            if ($ids) {
                $q->whereHas('interests', function ($w) use ($ids) {
                    $w->whereIn('interests.id', $ids);
                });
            } else {
                $q->whereRaw('1 = 0');
            }
        }

        if (!empty($filters['free'])) {
            $q->where(function ($w) {
                $w->where('events.price_status', 'free')
                    ->orWhere(function ($x) {
                        $x->where('events.price_min', 0)->whereNull('events.price_max');
                    });
            });
        }

        $priceMin = array_key_exists('price_min', $filters) ? (int) $filters['price_min'] : null;
        $priceMax = array_key_exists('price_max', $filters) ? (int) $filters['price_max'] : null;
        $priced = array_key_exists('priced', $filters)
            ? (filter_var($filters['priced'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true)
            : false;

        $hasRange = ($priceMin !== null) || ($priceMax !== null);
        $knownPrice = function ($w) {
            $w->where('events.price_status', 'free')
                ->orWhereNotNull('events.price_min')
                ->orWhereNotNull('events.price_max');
        };
        $minExpr = "COALESCE(events.price_min, events.price_max, CASE WHEN events.price_status='free' THEN 0 END)";
        $maxExpr = "COALESCE(events.price_max, events.price_min, CASE WHEN events.price_status='free' THEN 0 END)";

        if ($hasRange) {
            $q->where(function ($w) use ($knownPrice, $priced, $minExpr, $maxExpr, $priceMin, $priceMax) {
                $w->where(function ($x) use ($knownPrice, $minExpr, $maxExpr, $priceMin, $priceMax) {
                    $x->where($knownPrice);
                    if ($priceMin !== null) $x->whereRaw("$maxExpr >= ?", [$priceMin]);
                    if ($priceMax !== null) $x->whereRaw("$minExpr <= ?", [$priceMax]);
                });
                if (!$priced) {
                    $w->orWhere(function ($x) {
                        $x->whereNull('events.price_min')
                            ->whereNull('events.price_max')
                            ->whereRaw("COALESCE(events.price_status,'') <> 'free'");
                    });
                }
            });
        } elseif ($priced) {
            $q->where($knownPrice);
        }

        if (!empty($filters['tod'])) {
            $tod = (string) $filters['tod'];
            $q->whereNotNull('events.start_time');
            $hourExpr = "EXTRACT(HOUR FROM (events.start_time AT TIME ZONE 'Europe/Moscow'))";
            switch ($tod) {
                case 'morning': $q->whereRaw("{$hourExpr} >= 5 AND {$hourExpr} <= 11"); break;
                case 'day':     $q->whereRaw("{$hourExpr} >= 12 AND {$hourExpr} <= 16"); break;
                case 'evening': $q->whereRaw("{$hourExpr} >= 17 AND {$hourExpr} <= 22"); break;
                case 'night':
                    $q->where(function ($w) use ($hourExpr) {
                        $w->whereRaw("{$hourExpr} >= 23")->orWhereRaw("{$hourExpr} <= 4");
                    });
                    break;
            }
        }

        if ($excludeIds) {
            $q->whereNotIn('events.id', $excludeIds);
        }

        $total = (int) (clone $q)->toBase()->getCountForPagination();

        if ($total === 0) {
            return ['event' => null, 'total' => 0];
        }

        $q->reorder()->orderByRaw('RANDOM()');
        /** @var Event|null $event */
        $event = $q->limit(1)->first();

        if ($event !== null) {
            $this->hydrateImages(EloquentCollection::make([$event]));
        }

        return ['event' => $event, 'total' => $total];
    }

    public function findWithDetails(int $id): Event
    {
        $q = Event::query()
            ->from('events')
            ->select('events.*')
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->where('ct.status', 'active')
            ->whereNull('events.deleted_at')
            ->with([
                'community:id,name,city,avatar_url',
                'interests:id,name',
                'venue:id,slug,name,kind',
            ]);

        $this->addPastFlags($q);
        $this->addGrayRank($q);

        $this->excludeBlacklistedSources($q);

        $event = $q->where('events.id', $id)->firstOrFail();

        $this->hydrateImages(new EloquentCollection([$event]));

        $event->makeHidden(['__past_rank', '__is_past', '__gray_rank', '__img_rank', '__like_rank', '__score', '__unknown_last']);

        return $event;
    }

    public function findWebWithDetails(int $id): Event
    {
        $q = Event::query()
            ->select('events.*', 'ct.slug as city_slug')
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->where('ct.status', 'active')
            ->whereNull('events.deleted_at')
            ->where('events.id', $id)
            ->with([
                'community:id,name,city,avatar_url',
                'interests:id,slug,name',
                'venue:id,slug,name,kind',
                'eventSources:id,event_id,source,post_external_id,external_url,published_at,images,generated_link,social_link_id',
                'originalPost:id,text',
            ]);

        $this->addPastFlags($q);
        $this->addGrayRank($q);

        $this->excludeBlacklistedSources($q);

        $event = $q->firstOrFail();

        $this->hydrateImages(new EloquentCollection([$event]));

        $event->makeHidden(['__past_rank', '__is_past', '__gray_rank', '__img_rank', '__like_rank', '__score', '__unknown_last']);

        return $event;
    }

    /**
     * Похожие события по интересам (Interests Этап 3).
     *
     * Берёт интересы события $eventId и ищет другие события того же города
     * с пересечением по event_interest, ранжируя по числу общих интересов
     * (DESC), затем тем же приоритетом что лента (не-past → с картинкой →
     * не-gray → ближе по дате).
     *
     * Видимость — ровно как у /web/events: cities.active, не-deleted,
     * future-окно (PAST_LOOKBACK_DAYS), excludeBlacklistedSources,
     * applyMainFeedTaxonomyFilter (kids/family + official/religious скрыты).
     * include_all сюда не пробрасываем — related всегда в формате ленты.
     *
     * Пустой результат (у события нет интересов / нет пересечений) штатен:
     * вызывающий фронт показывает фолбэк «другие события города».
     */
    public function relatedByInterests(int $eventId, int $limit = 8): EloquentCollection
    {
        $limit = max(1, min($limit, 24));

        $base = Event::query()
            ->whereNull('deleted_at')
            ->select('id', 'city_id')
            ->find($eventId);

        if ($base === null) {
            return new EloquentCollection();
        }

        $interestIds = DB::table('event_interest')
            ->where('event_id', $eventId)
            ->pluck('interest_id')
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($interestIds)) {
            return new EloquentCollection();
        }

        $nowMsk = now('Europe/Moscow');
        $fromDateMsk = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS)->toDateString();
        $cutoffTs = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS);

        // $interestIds — int[] после cast, безопасно инлайнить в коррелированный
        // count (он же — ключ сортировки по силе пересечения).
        $idsList = implode(',', $interestIds);
        $sharedSql = "(SELECT COUNT(*) FROM event_interest ei
            WHERE ei.event_id = events.id AND ei.interest_id IN ($idsList))";

        $q = Event::query()
            ->select('events.*', 'ct.slug as city_slug')
            ->selectRaw("$sharedSql as __shared_interests")
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->where('ct.status', 'active')
            ->whereNull('events.deleted_at')
            ->where('events.city_id', (int) $base->city_id)
            ->where('events.id', '<>', $eventId)
            ->where(function ($w) use ($cutoffTs, $fromDateMsk) {
                $w->where('events.start_time', '>=', $cutoffTs)
                    ->orWhere(function ($x) use ($fromDateMsk) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $fromDateMsk);
                    });
            })
            ->whereHas('interests', function ($w) use ($interestIds) {
                $w->whereIn('interests.id', $interestIds);
            })
            ->with([
                'interests:id,slug,name',
                'venue:id,slug,name,kind',
            ]);

        $this->addPastFlags($q);
        $this->addGrayRank($q);
        $this->addImgRank($q);

        $this->excludeBlacklistedSources($q);
        $this->applyMainFeedTaxonomyFilter($q, []);

        $q->orderByRaw('__shared_interests desc')
            ->orderBy('__past_rank', 'asc')
            ->orderBy('__img_rank', 'asc')
            ->orderBy('__gray_rank', 'asc')
            ->orderByRaw('events.start_date asc nulls last')
            ->orderByRaw('events.start_time asc nulls last')
            ->orderBy('events.id', 'asc');

        $events = $q->limit($limit)->get();

        $this->hydrateImages($events);

        $events->each(function ($e) {
            $e->makeHidden(['__past_rank', '__is_past', '__gray_rank', '__img_rank', '__shared_interests']);
        });

        return $events;
    }

    private function hydrateImages(EloquentCollection $events): void
    {
        if ($events->isEmpty()) return;

        $eventIds = $events->pluck('id')->all();
        $postIds  = $events->pluck('original_post_id')->filter()->unique()->values()->all();

        $esRows = DB::table('event_sources')
            ->select('event_id', 'images')
            ->whereIn('event_id', $eventIds)
            ->get()
            ->groupBy('event_id')
            ->map(function (Collection $rows) {
                $all = [];
                foreach ($rows as $r) {
                    $arr = is_string($r->images) ? json_decode($r->images, true) : $r->images;
                    if (is_array($arr)) {
                        foreach ($arr as $u) {
                            if (is_string($u) && $u !== '') $all[] = $u;
                        }
                    }
                }
                $seen = [];
                $uniq = [];
                foreach ($all as $u) {
                    if (!isset($seen[$u])) {
                        $seen[$u] = true;
                        $uniq[] = $u;
                    }
                }
                return $uniq;
            });

        $cpRows = collect();
        if ($postIds) {
            $cpRows = DB::table('attachments')
                ->select('parent_id', 'type', 'url', 'preview_url', 'order', 'id')
                ->where('parent_type', 'App\\Models\\ContextPost')
                ->whereIn('parent_id', $postIds)
                ->orderBy('order')
                ->orderBy('id')
                ->get()
                ->groupBy('parent_id')
                ->map(function (Collection $rows) {
                    $urls = [];
                    foreach ($rows as $r) {
                        if (in_array($r->type, ['image', 'photo'], true)) {
                            $u = $r->url ?: $r->preview_url;
                            if (is_string($u) && $u !== '') $urls[] = $u;
                        }
                    }
                    $seen = [];
                    $uniq = [];
                    foreach ($urls as $u) {
                        if (!isset($seen[$u])) {
                            $seen[$u] = true;
                            $uniq[] = $u;
                        }
                    }
                    return $uniq;
                });
        }

        $evRows = DB::table('attachments')
            ->select('parent_id', 'type', 'url', 'preview_url', 'order', 'id')
            ->where('parent_type', 'App\\Models\\Event')
            ->whereIn('parent_id', $eventIds)
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id')
            ->map(function (Collection $rows) {
                $urls = [];
                foreach ($rows as $r) {
                    if (in_array($r->type, ['image', 'photo'], true)) {
                        $u = $r->url ?: $r->preview_url;
                        if (is_string($u) && $u !== '') $urls[] = $u;
                    }
                }
                $seen = [];
                $uniq = [];
                foreach ($urls as $u) {
                    if (!isset($seen[$u])) {
                        $seen[$u] = true;
                        $uniq[] = $u;
                    }
                }
                return $uniq;
            });

        $events->each(function (Event $e) use ($esRows, $cpRows, $evRows) {
            $images = $esRows->get($e->id, []);
            if (empty($images) && $e->original_post_id) {
                $images = $cpRows->get($e->original_post_id, []);
            }
            if (empty($images)) {
                $images = $evRows->get($e->id, []);
            }

            $images = array_values($images);

            // Эвристический выбор обложки: сортируем так, чтобы первая
            // картинка была наиболее «карточной» (правильные пропорции,
            // достаточный размер). Поведение задаётся `App\Support\CoverPicker`.
            if (count($images) > 1) {
                $images = \App\Support\CoverPicker::pickBest($images);
            }

            $e->setAttribute('images', $images);
            $e->setAttribute('poster', $images[0] ?? null);
        });
    }

    private function addPastFlags($q): void
    {
        $graceHours = (int) self::PAST_GRACE_HOURS;

        $caseSql = "CASE WHEN (
            (events.start_time IS NOT NULL AND events.start_time < (now() - interval '{$graceHours} hours'))
            OR
            (events.start_time IS NULL AND events.start_date IS NOT NULL AND events.start_date < (now() AT TIME ZONE 'Europe/Moscow')::date)
        ) THEN 1 ELSE 0 END";

        $q->selectRaw("$caseSql as __past_rank");
        $q->selectRaw("(($caseSql) = 1) as __is_past");
    }

    private function addGrayRank($q): void
    {
        // gray-only: есть хотя бы один gray-источник
        // и нет ни одного "хорошего" источника (active/NULL или source без social_link_id)
        $sql = "CASE WHEN (
            EXISTS (
                SELECT 1
                FROM event_sources es
                JOIN community_social_links csl ON csl.id = es.social_link_id
                WHERE es.event_id = events.id
                  AND csl.status = 'gray'
            )
            AND NOT EXISTS (
                SELECT 1
                FROM event_sources es2
                LEFT JOIN community_social_links csl2 ON csl2.id = es2.social_link_id
                WHERE es2.event_id = events.id
                  AND (
                    es2.social_link_id IS NULL
                    OR COALESCE(csl2.status, 'active') = 'active'
                  )
            )
        ) THEN 1 ELSE 0 END";

        $q->selectRaw("$sql as __gray_rank");
    }

    /**
     * SQL-условие «у события есть фото» (event_sources.images или attachments
     * самого события / исходного поста). Общее для __img_rank и __top_score.
     */
    private function hasPhotoSql(): string
    {
        return "(
            EXISTS (
                SELECT 1 FROM event_sources es
                WHERE es.event_id = events.id
                  AND es.images IS NOT NULL
                  AND es.images::text NOT IN ('[]','null')
                LIMIT 1
            )
            OR EXISTS (
                SELECT 1 FROM attachments a
                WHERE a.parent_type = 'App\\\\Models\\\\Event'
                  AND a.parent_id = events.id
                  AND a.type IN ('image','photo')
                  AND (a.url IS NOT NULL OR a.preview_url IS NOT NULL)
                LIMIT 1
            )
            OR EXISTS (
                SELECT 1 FROM attachments ap
                WHERE ap.parent_type = 'App\\\\Models\\\\ContextPost'
                  AND ap.parent_id = events.original_post_id
                  AND ap.type IN ('image','photo')
                  AND (ap.url IS NOT NULL OR ap.preview_url IS NOT NULL)
                LIMIT 1
            )
        )";
    }

    private function addImgRank($q): void
    {
        $q->selectRaw('CASE WHEN '.$this->hasPhotoSql().' THEN 0 ELSE 1 END as __img_rank');
    }

    /**
     * __top_score — «интересность» события для ранжированной ленты (sort=top).
     * Порт весов EventBroadcastScorer (Telegram\Scoring) в SQL: тот же набор
     * сигналов, что выбирает «богатые» события для автопостинга, переиспользуем
     * для главной. interests-компонент = 0 пока дерево интересов не наполнено
     * (event_interest пуст) — заработает автоматически после наполнения.
     *
     * Свежесть считаем от coalesce(start_time::date, start_date) — для ленты
     * date-only события НЕ штрафуем как -25 (в скорере start_time=null→FAR; здесь
     * мягче, точное время уже отдельно вознаграждено W_TIME_EXACT).
     */
    private function addTopScore($q): void
    {
        $photo = $this->hasPhotoSql();

        $sql = "(
            CASE WHEN $photo THEN 40 ELSE 0 END
            + CASE WHEN events.house_fias_id IS NOT NULL AND events.house_fias_id <> '' THEN 20 ELSE 0 END
            + CASE WHEN events.venue_id IS NOT NULL THEN 15 ELSE 0 END
            + CASE WHEN (SELECT count(*) FROM event_interest ei WHERE ei.event_id = events.id) >= 1 THEN 15 ELSE 0 END
            + CASE WHEN events.tickets_status = 'available' THEN 10 ELSE 0 END
            + CASE WHEN events.price_status IN ('free','priced') OR events.price_min IS NOT NULL THEN 5 ELSE 0 END
            + CASE WHEN char_length(coalesce(events.description, '')) >= 120 THEN 10 ELSE 0 END
            + CASE WHEN events.time_precision = 'datetime' THEN 5 ELSE 0 END
            -- свежесть-бонус: только что спарсенные события поднимаются над «висящими»
            -- (умеренно — не перебивает крупные события; ротация новых VK-постов в топ)
            + CASE
                WHEN events.created_at >= now() - interval '3 days' THEN 10
                WHEN events.created_at >= now() - interval '7 days' THEN 5
                ELSE 0
              END
            + CASE
                WHEN coalesce(events.start_time::date, events.start_date) IS NULL THEN -25
                WHEN coalesce(events.start_time::date, events.start_date) <= (now()::date + 2) THEN 0
                WHEN coalesce(events.start_time::date, events.start_date) <= (now()::date + 7) THEN -5
                WHEN coalesce(events.start_time::date, events.start_date) <= (now()::date + 30) THEN -12
                ELSE -25
              END
        )";

        $q->selectRaw("$sql as __top_score");
    }

    private function normalizeQ(?string $q): ?string
    {
        $q = trim((string) $q);
        if ($q === '') return null;

        $q = mb_strtolower($q);
        $q = str_replace('ё', 'е', $q);
        $q = str_replace('-', ' ', $q);
        $q = preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $q);
        $q = preg_replace('~\s+~u', ' ', $q);
        $q = trim($q);

        return $q !== '' ? $q : null;
    }

    private function pickFuzzyToken(string $qNorm): string
    {
        $parts = preg_split('~\s+~u', $qNorm, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) return $qNorm;

        $token = '';
        foreach ($parts as $p) {
            if (mb_strlen($p) > mb_strlen($token)) $token = $p;
        }

        return $token !== '' ? $token : $qNorm;
    }

    private function fuzzyThreshold(string $token): float
    {
        $n = mb_strlen($token);
        if ($n <= 4) return 0.20;
        if ($n <= 6) return 0.18;
        return 0.14;
    }

    private function trgmEnabled(): bool
    {
        if ($this->hasTrgm !== null) return $this->hasTrgm;

        try {
            DB::selectOne("select similarity('a','a') as s");
            $this->hasTrgm = true;
        } catch (\Throwable $e) {
            $this->hasTrgm = false;
        }

        return $this->hasTrgm;
    }

    private function wordSimEnabled(): bool
    {
        if ($this->hasWordSim !== null) return $this->hasWordSim;

        try {
            DB::selectOne("select word_similarity('a','a') as s");
            $this->hasWordSim = true;
        } catch (\Throwable $e) {
            $this->hasWordSim = false;
        }

        return $this->hasWordSim;
    }

    private function excludeBlacklistedSources($q): void
    {
        // Скрываем событие только если:
        // - есть хотя бы один source с black ссылкой
        // - и нет ни одного source, который НЕ black (включая source без social_link_id)
        $q->whereRaw("
        NOT (
            EXISTS (
                SELECT 1
                FROM event_sources es
                JOIN community_social_links csl ON csl.id = es.social_link_id
                WHERE es.event_id = events.id
                  AND csl.status = 'black'
            )
            AND NOT EXISTS (
                SELECT 1
                FROM event_sources es2
                LEFT JOIN community_social_links csl2 ON csl2.id = es2.social_link_id
                WHERE es2.event_id = events.id
                  AND (
                    es2.social_link_id IS NULL
                    OR COALESCE(csl2.status, 'active') <> 'black'
                  )
            )
        )
    ");
    }

    /**
     * Скрывает события вне формата «общегородская развлекательная лента»:
     *  - audience IN ('kids','family') — детский / семейный профиль,
     *  - content_kind NOT IN базового набора — официально-протокольное,
     *    патриотические церемонии, религиозный обряд.
     *
     * NULL-значения (legacy events до v11/v12 или редкие пропуски LLM) НЕ
     * скрываем — backwards-compat и защита от data-loss на backlog'е.
     *
     * Override: $filters['include_all'] === true (через `?include_all=1`) —
     * пропускает фильтр целиком. Для админки / dev-режима / специальных
     * страниц «увидеть всё».
     */
    private function applyMainFeedTaxonomyFilter($q, array $filters): void
    {
        $includeAll = array_key_exists('include_all', $filters)
            && filter_var($filters['include_all'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;

        if ($includeAll) {
            return;
        }

        $q->where(function ($w) {
            $w->whereNull('events.audience')
                ->orWhereNotIn('events.audience', ['kids', 'family']);
        });

        $q->where(function ($w) {
            $w->whereNull('events.content_kind')
                ->orWhereIn('events.content_kind', [
                    'entertainment', 'culture', 'education', 'sport', 'civic',
                ]);
        });
    }

    /**
     * Result is stored as attributes:
     * - group_dates: [{id,start_at,start_date,time_precision,time_text}, ...]
     * - group_count: int (реальный размер группы по “видимым” событиям)
     */
    /**
     * Жадная диверсификация: переставляет события так, чтобы не было >2 подряд
     * одного content_kind, минимально нарушая исходный (score) порядок. События
     * без content_kind не считаются «одинаковыми» (пустой kind не группирует).
     */
    private function diversifyByContentKind(EloquentCollection $events): EloquentCollection
    {
        if ($events->count() < 3) {
            return $events;
        }

        $kindOf = fn ($e) => (string) ($e->content_kind ?? '');
        $pool = $events->all(); // в score-порядке
        $result = [];

        while ($pool) {
            $pickIdx = 0;
            $n = count($result);
            // если последние два уже одного непустого kind — берём первого с другим
            if ($n >= 2) {
                $last = $kindOf($result[$n - 1]);
                if ($last !== '' && $last === $kindOf($result[$n - 2])) {
                    foreach ($pool as $i => $cand) {
                        if ($kindOf($cand) !== $last) {
                            $pickIdx = $i;
                            break;
                        }
                    }
                }
            }
            $result[] = $pool[$pickIdx];
            array_splice($pool, $pickIdx, 1);
        }

        return new EloquentCollection($result);
    }

    private function hydrateGroupDates(EloquentCollection $events): void
    {
        if ($events->isEmpty()) return;

        $MAX_DATES = 12; // чтобы group.dates не раздувал ответы

        $repGroupIds = $events->pluck('event_group_id')
            ->filter(fn($v) => is_numeric($v) && (int)$v > 0)
            ->map(fn($v) => (int)$v)
            ->unique()
            ->values()
            ->all();

        if (!$repGroupIds) return;

        // federation-aware ключ группы: COALESCE(federation_id, id). Расширяем
        // rep-группы до всех групп их федераций, чтобы chip'ы сеансов собрались
        // по ВСЕЙ федерации (cross-community), а не только по группе rep'а.
        $fedKeys = DB::table('event_groups')
            ->whereIn('id', $repGroupIds)
            ->whereNull('deleted_at')
            ->selectRaw('DISTINCT COALESCE(federation_id, id) as fk')
            ->pluck('fk')
            ->map(fn($v) => (int)$v)
            ->all();

        if (!$fedKeys) return;

        // rep.event_group_id → fed_key (для раскладки результата на rep-события)
        $repFed = DB::table('event_groups')
            ->whereIn('id', $repGroupIds)
            ->selectRaw('id, COALESCE(federation_id, id) as fk')
            ->pluck('fk', 'id')
            ->map(fn($v) => (int)$v)
            ->all();

        // те же правила, что /web/event-groups/{id} (и /web/events)
        $nowMsk = now('Europe/Moscow');
        $fromDateMsk = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS)->toDateString();
        $cutoffTs = $nowMsk->copy()->subDays(self::PAST_LOOKBACK_DAYS);

        $q = DB::table('events as e')
            ->join('event_groups as eg', 'eg.id', '=', 'e.event_group_id')
            ->join('cities as ct', 'ct.id', '=', 'e.city_id')
            ->select(['e.id', 'e.event_group_id', 'e.start_time', 'e.start_date', 'e.time_precision', 'e.time_text'])
            ->selectRaw('COALESCE(eg.federation_id, eg.id) as __fed_key')
            ->selectRaw('count(*) OVER (PARTITION BY COALESCE(eg.federation_id, eg.id)) as __grp_count')
            ->whereNull('eg.deleted_at')
            ->where('ct.status', 'active')
            ->whereNull('e.deleted_at')
            ->whereIn(DB::raw('COALESCE(eg.federation_id, eg.id)'), $fedKeys)
            ->where(function ($w) use ($cutoffTs, $fromDateMsk) {
                $w->where('e.start_time', '>=', $cutoffTs)
                    ->orWhere(function ($x) use ($fromDateMsk) {
                        $x->whereNull('e.start_time')
                            ->whereNotNull('e.start_date')
                            ->where('e.start_date', '>=', $fromDateMsk);
                    });
            });

        // blacklist filter (копия логики excludeBlacklistedSources(), но с alias e)
        $q->whereRaw("
            NOT (
                EXISTS (
                    SELECT 1
                    FROM event_sources es
                    JOIN community_social_links csl ON csl.id = es.social_link_id
                    WHERE es.event_id = e.id
                      AND csl.status = 'black'
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM event_sources es2
                    LEFT JOIN community_social_links csl2 ON csl2.id = es2.social_link_id
                    WHERE es2.event_id = e.id
                      AND (
                        es2.social_link_id IS NULL
                        OR COALESCE(csl2.status, 'active') <> 'black'
                      )
                )
            )
        ");

        $rows = $q
            ->orderByRaw('COALESCE(eg.federation_id, eg.id) asc')
            ->orderByRaw('e.start_date asc nulls last')
            ->orderByRaw('e.start_time asc nulls last')
            ->orderBy('e.id', 'asc')
            ->get();

        $map = [];
        $cntMap = [];

        foreach ($rows as $r) {
            $gid = (int) $r->__fed_key; // ключ карты = федерация (или сама группа, если не федерирована)
            if (!$gid) continue;

            $grpCount = (int) ($r->__grp_count ?? 0);

            if ($grpCount < 2) continue;

            $cntMap[$gid] = $grpCount;

            if (!isset($map[$gid])) $map[$gid] = [];
            if (count($map[$gid]) >= $MAX_DATES) {
                // уже набрали лимит дат — остальное не тащим
                continue;
            }

            $startAt = null;
            if (!empty($r->start_time)) {
                try {
                    $startAt = CarbonImmutable::parse($r->start_time)->toISOString();
                } catch (\Throwable $e) {
                    $startAt = null;
                }
            }

            $map[$gid][] = [
                'id'             => (int) $r->id,
                'start_at'       => $startAt,
                'start_date'     => $r->start_date ? substr((string) $r->start_date, 0, 10) : null,
                'time_precision' => (string) ($r->time_precision ?? 'datetime'),
                'time_text'      => $r->time_text !== null ? (string) $r->time_text : null,
            ];
        }

        $events->each(function (Event $e) use ($map, $cntMap, $repFed) {
            $egid = (int) ($e->event_group_id ?? 0);
            $gid = $repFed[$egid] ?? $egid; // event_group_id rep'а → его fed-ключ
            if ($gid > 0 && isset($map[$gid])) {
                $e->setAttribute('group_dates', $map[$gid]); // уже лимитировано
                $e->setAttribute('group_count', (int) ($cntMap[$gid] ?? count($map[$gid])));
            }
        });
    }

    /**
     * Для каждого rep-event из payload находит его cluster (events с тем же
     * event_sources.source + post_external_id) и навешивает атрибут
     * `siblings` — массив preview-DTO для каждого «брата» (без самого rep'а).
     *
     * Скоп: только active не-удалённые events. Cross-post: если rep в нескольких
     * (source, post_external_id), выбираем кластер с наибольшим количеством
     * братьев.
     */
    /**
     * @param int[]|null $eligibleEventIds Если задано — siblings выбираются
     *   ТОЛЬКО среди этих event_ids. Используется для синхронизации с
     *   `total_events` count'ом (TASKS.md §14): главная не должна показывать
     *   в карусели events, которые не учтены в counter'е (другой город,
     *   blacklisted source, past beyond grace и т.п.).
     */
    private function hydrateSiblings(EloquentCollection $events, ?array $eligibleEventIds = null): void
    {
        if ($events->isEmpty()) return;

        $MAX_SIBLINGS = 13; // p90 кластер на dev = 14 events, 13 siblings + rep

        $repIds = $events->pluck('id')
            ->filter(fn ($v) => is_numeric($v) && (int) $v > 0)
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
        if (!$repIds) return;

        // Все (source, post_external_id) пары, в которых участвуют rep'ы.
        // Один rep может быть в нескольких — cross-post.
        $repPairs = DB::table('event_sources')
            ->whereIn('event_id', $repIds)
            ->select(['event_id', 'source', 'post_external_id'])
            ->get();

        if ($repPairs->isEmpty()) return;

        // Уникальные пары для batch-fetch всех cluster-events.
        $pairKeys = [];
        $pairs = [];
        foreach ($repPairs as $rp) {
            $key = $rp->source . '|' . $rp->post_external_id;
            if (!isset($pairKeys[$key])) {
                $pairKeys[$key] = true;
                $pairs[] = [(string) $rp->source, (string) $rp->post_external_id];
            }
        }

        if (!$pairs) return;

        // SELECT всех events во всех этих парах. WHERE (source, post_external_id) IN ((..),(..))
        // через or'd группу условий — Laravel не имеет красивого тапла-IN.
        $q = DB::table('events as e')
            ->join('event_sources as es', 'es.event_id', '=', 'e.id')
            ->join('cities as ct', 'ct.id', '=', 'e.city_id')
            ->where('ct.status', 'active')
            ->whereNull('e.deleted_at')
            ->where('e.status', 'active')
            ->select([
                'e.id',
                'e.title',
                'e.start_time',
                'e.start_date',
                'e.time_precision',
                'e.time_text',
                'es.source',
                'es.post_external_id',
            ])
            ->where(function ($w) use ($pairs) {
                foreach ($pairs as $pair) {
                    $w->orWhere(function ($x) use ($pair) {
                        $x->where('es.source', $pair[0])
                            ->where('es.post_external_id', $pair[1]);
                    });
                }
            });

        // Ограничение: siblings — только subset eligible events
        // (тех же, что попали в total_events). Гарантирует консистентность
        // counter'а на главной (TASKS.md §14).
        if ($eligibleEventIds !== null) {
            // Включаем сами rep'ы (на случай если они есть в pool — обычно
            // да) и всё, что в pool. whereIn по пустому массиву = WHERE 0=1
            // (Laravel) → siblings пустые, что корректно при пустом pool.
            $q->whereIn('e.id', $eligibleEventIds);
        }

        $rows = $q->get();

        // Сгруппировать по cluster key.
        $clusterMap = [];
        foreach ($rows as $r) {
            $key = $r->source . '|' . $r->post_external_id;
            if (!isset($clusterMap[$key])) $clusterMap[$key] = [];
            $clusterMap[$key][] = $r;
        }

        // Rep -> список его кластеров.
        $repToClusterKeys = [];
        foreach ($repPairs as $rp) {
            $rid = (int) $rp->event_id;
            $key = $rp->source . '|' . $rp->post_external_id;
            if (!isset($repToClusterKeys[$rid])) $repToClusterKeys[$rid] = [];
            $repToClusterKeys[$rid][] = $key;
        }

        // Cutoff'ы для фильтрации past siblings — те же, что в applyOnlyActual
        // / addPastFlags, чтобы поведение карусели и счётчика на главной
        // совпадало с критериями «актуальности» (TASKS.md §13p).
        $cutoffTs = now('Europe/Moscow')->copy()->subHours(self::PAST_GRACE_HOURS);
        $todayMsk = now('Europe/Moscow')->toDateString();

        $events->each(function (Event $e) use ($repToClusterKeys, $clusterMap, $MAX_SIBLINGS, $cutoffTs, $todayMsk) {
            $rid = (int) $e->id;
            $keys = $repToClusterKeys[$rid] ?? [];
            if (!$keys) return;

            // Cross-post: выбираем cluster с максимальным размером.
            $bestKey = null;
            $bestSize = 0;
            foreach ($keys as $key) {
                $size = isset($clusterMap[$key]) ? count($clusterMap[$key]) : 0;
                if ($size > $bestSize) {
                    $bestSize = $size;
                    $bestKey = $key;
                }
            }
            if ($bestKey === null || $bestSize < 2) return;

            $siblings = [];
            foreach ($clusterMap[$bestKey] as $r) {
                if ((int) $r->id === $rid) continue; // self — представитель

                // §13p: отбрасываем уже прошедшие siblings — в карусели
                // «другие даты этого события» прошлые даты бесполезны.
                // Семантика: если у rep'а есть future-siblings, кластер
                // в актуальной ленте; past-даты этого же поста просто не
                // показываем.
                if (!$this->siblingIsFuture($r, $cutoffTs, $todayMsk)) continue;

                $startAt = null;
                if (!empty($r->start_time)) {
                    try {
                        $startAt = CarbonImmutable::parse($r->start_time)->toISOString();
                    } catch (\Throwable $ex) {
                        $startAt = null;
                    }
                }

                $siblings[] = [
                    'id'             => (int) $r->id,
                    'title'          => (string) ($r->title ?? ''),
                    'start_at'       => $startAt,
                    'start_date'     => $r->start_date ? substr((string) $r->start_date, 0, 10) : null,
                    'time_precision' => (string) ($r->time_precision ?? 'datetime'),
                    'time_text'      => $r->time_text !== null ? (string) $r->time_text : null,
                ];
            }

            // Sort by earliest start (для предсказуемого порядка свайпа)
            usort($siblings, function ($a, $b) {
                $aKey = (string) ($a['start_at'] ?? $a['start_date'] ?? '');
                $bKey = (string) ($b['start_at'] ?? $b['start_date'] ?? '');
                return strcmp($aKey, $bKey);
            });

            if (count($siblings) > $MAX_SIBLINGS) {
                $siblings = array_slice($siblings, 0, $MAX_SIBLINGS);
            }

            if (!empty($siblings)) {
                $e->setAttribute('siblings', $siblings);
            }
        });
    }

    private function applyOnlyActual($q): void
    {
        $nowMsk = now('Europe/Moscow');
        $todayMsk = $nowMsk->toDateString();
        $cutoffTs = $nowMsk->copy()->subHours(self::PAST_GRACE_HOURS);

        $q->where(function ($w) use ($cutoffTs, $todayMsk) {
            $w->where('events.start_time', '>=', $cutoffTs)
                ->orWhere(function ($x) use ($todayMsk) {
                    $x->whereNull('events.start_time')
                        ->whereNotNull('events.start_date')
                        ->where('events.start_date', '>=', $todayMsk);
                });
        });
    }

    /**
     * Sibling-строка — будущая (по тем же критериям, что applyOnlyActual)?
     * Используется в hydrateSiblings для отбрасывания past дат из карусели
     * (TASKS.md §13p).
     */
    private function siblingIsFuture(object $r, \Carbon\CarbonInterface $cutoffTs, string $todayMsk): bool
    {
        if (!empty($r->start_time)) {
            try {
                return CarbonImmutable::parse($r->start_time)->greaterThanOrEqualTo($cutoffTs);
            } catch (\Throwable $ex) {
                return true; // невалидный TS — оставим, не выкидываем
            }
        }
        if (!empty($r->start_date)) {
            $d = substr((string) $r->start_date, 0, 10);
            return $d >= $todayMsk;
        }
        return true; // нет даты — не выкидываем (странный кейс, оставим решать выше)
    }
}
