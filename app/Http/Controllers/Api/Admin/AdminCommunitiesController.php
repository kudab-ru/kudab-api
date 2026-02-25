<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Communities\AdminCommunitiesImportRequest;
use App\Http\Requests\Admin\Communities\AdminCommunitiesIndexRequest;
use App\Http\Requests\Admin\Communities\AdminCommunitiesStoreRequest;
use App\Http\Requests\Admin\Communities\AdminCommunitiesUpdateRequest;
use App\Http\Requests\Admin\Communities\AdminCommunitiesVerifyRequest;
use App\Http\Resources\Admin\CommunityResource as AdminCommunityResource;
use App\Models\Community;
use App\Models\CommunitySocialLink;
use App\Models\SocialNetwork;
use App\Services\Vk\VkCommunityResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminCommunitiesController extends Controller
{
    public function index(AdminCommunitiesIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $currentPage = (int)($validated['page'] ?? 1);
        $perPage = max(1, min((int)($validated['per_page'] ?? 20), 100));

        $communitiesQuery = Community::query()
            ->with(['city:id,name,slug']);

        $withDeleted = (bool)($validated['with_deleted'] ?? false);
        $onlyDeleted = (bool)($validated['only_deleted'] ?? false);

        if ($withDeleted || $onlyDeleted) {
            $communitiesQuery->withTrashed();
        }
        if ($onlyDeleted) {
            $communitiesQuery->onlyTrashed();
        }

        if (!empty($validated['city_id'])) {
            $communitiesQuery->where('city_id', (int)$validated['city_id']);
        }

        if (!empty($validated['verification_status'])) {
            $communitiesQuery->where('verification_status', (string)$validated['verification_status']);
        }

        if (!empty($validated['q'])) {
            $term = '%' . trim((string)$validated['q']) . '%';

            $communitiesQuery->where(function ($where) use ($term) {
                $where->where('name', 'ILIKE', $term)
                    ->orWhere('description', 'ILIKE', $term);
            });
        }

        $sort = (string)($validated['sort'] ?? 'id');
        $dir = strtolower((string)($validated['dir'] ?? 'desc'));
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        if ($sort === 'last_checked_at') {
            $communitiesQuery->orderByRaw("last_checked_at {$dir} nulls last")->orderBy('id', 'desc');
        } else {
            $communitiesQuery->orderBy($sort, $dir);
        }

        $page = $communitiesQuery->paginate($perPage, ['*'], 'page', $currentPage);

        $items = AdminCommunityResource::collection($page->getCollection())->toArray($request);

        return response()->json([
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'data' => $items,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $community = Community::withTrashed()
            ->with(['city:id,name,slug'])
            ->findOrFail($id);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ]);
    }

    public function store(AdminCommunitiesStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $community = new Community();
        $community->fill($data);

        if (array_key_exists('city_id', $data)) {
            $community->city_id = $data['city_id'];
        }

        $community->save();

        $community = $community->fresh(['city:id,name,slug']);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ], 201);
    }

    public function update(AdminCommunitiesUpdateRequest $request, int $id): JsonResponse
    {
        $community = Community::query()->findOrFail($id);

        $data = $request->validated();

        $community->fill($data);

        // Ручной финал: фиксируем в meta, чтобы авто-джоба НЕ перетирала решение.
        if (array_key_exists('verification_status', $data)) {
            $status = (string)($data['verification_status'] ?? '');

            $metaRaw = $community->verification_meta ?? null;
            $meta = is_array($metaRaw) ? $metaRaw : (is_string($metaRaw) ? json_decode($metaRaw, true) : []);
            if (!is_array($meta)) $meta = [];

            if (in_array($status, ['approved', 'rejected'], true)) {
                $meta['manual_final'] = true;
                $meta['manual'] = [
                    'status' => $status,
                    'at' => now()->toIso8601String(),
                    'by_user_id' => optional($request->user())->id,
                ];
            } elseif ($status === 'pending') {
                unset($meta['manual_final'], $meta['manual']);
            }

            $community->verification_meta = $meta;

            // Консистентность флагов при ручной правке (если поле есть)
            if (array_key_exists('is_verified', $community->getAttributes())) {
                $community->is_verified = ($status === 'approved');
            }
        }

        if (array_key_exists('city_id', $data)) {
            $community->city_id = $data['city_id'];
        }

        $community->save();

        $community = $community->fresh(['city:id,name,slug']);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ]);
    }

    public function destroy(int|string $id): JsonResponse
    {
        $community = Community::withTrashed()->findOrFail($id);

        if (!$community->trashed()) {
            $community->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $community = Community::withTrashed()->findOrFail($id);

        if ($community->trashed()) {
            $community->restore();
        }

        $community = $community->refresh()->load(['city:id,name,slug']);

        return response()->json([
            'data' => (new AdminCommunityResource($community))->toArray($request),
        ]);
    }

    /**
     * POST /api/admin/communities/import
     * Body: { url: string, auto_verify?: bool }
     *
     * Импорт:
     * - нормализуем ссылку
     * - для VK (если есть token) синхронно подтягиваем name/description/avatar/image/external_id/canonical_url
     * - создаём или обновляем community + community_social_links
     * - auto_verify: если true — ставим outbox на верификацию
     */
    public function import(AdminCommunitiesImportRequest $request, VkCommunityResolver $vk): JsonResponse
    {
        $validated = $request->validated();

        $inputUrl = trim((string)$validated['url']);
        $autoVerify = (bool)($validated['auto_verify'] ?? false);

        [$normalizedUrl, $host, $path, $query] = $this->parseUrlParts($inputUrl);
        [$sourceKey, $socialNetwork] = $this->resolveSocialNetworkByHost($host);

        $externalCommunityId = $this->extractExternalCommunityId($sourceKey, $path, $query);

        $resolvedName = null;
        $resolvedDescription = null;
        $resolvedAvatarUrl = null;
        $resolvedImageUrl = null;
        $canonicalUrl = $normalizedUrl;

        $vkConfigured = (string)config('services.vk.token') !== '' && (string)config('services.vk.version') !== '';

        // VK: онлайн-резолв (имя/описание/фото/external_id/url)
        if ($sourceKey === 'vk' && $vkConfigured) {
            $resolved = $vk->resolve($normalizedUrl);

            $externalCommunityId = (string)$resolved['external_community_id'];
            $resolvedName = (string)$resolved['name'];
            $resolvedDescription = $resolved['description'] ?? null;
            $resolvedAvatarUrl = $resolved['avatar_url'] ?? null;
            $resolvedImageUrl = $resolved['image_url'] ?? null;
            $canonicalUrl = (string)$resolved['canonical_url'];
        }

        // Идемпотентность: ищем по canonical_url, и по (social_network_id + external_community_id) если он есть
        $existingLink = CommunitySocialLink::query()
            ->where('url', $canonicalUrl)
            ->when($externalCommunityId !== null, function ($q) use ($socialNetwork, $externalCommunityId) {
                $q->orWhere(function ($qq) use ($socialNetwork, $externalCommunityId) {
                    $qq->where('social_network_id', $socialNetwork->id)
                        ->where('external_community_id', $externalCommunityId);
                });
            })
            ->first();

        if ($existingLink) {
            $community = $existingLink->community;

            $changed = false;

            // “Лёгкое обновление”: если VK смог отдать профиль — перезаписываем поля
            if ($community && $resolvedName !== null && $resolvedName !== '') {
                if ((string)$community->name !== $resolvedName) {
                    $community->name = $resolvedName;
                    $changed = true;
                }

                // description/avatar/image обновляем только если пришло не-null (чтобы не “затирать в пустоту”)
                if ($resolvedDescription !== null && (string)$community->description !== (string)$resolvedDescription) {
                    $community->description = $resolvedDescription;
                    $changed = true;
                }
                if ($resolvedAvatarUrl !== null && (string)$community->avatar_url !== (string)$resolvedAvatarUrl) {
                    $community->avatar_url = $resolvedAvatarUrl;
                    $changed = true;
                }
                if ($resolvedImageUrl !== null && (string)$community->image_url !== (string)$resolvedImageUrl) {
                    $community->image_url = $resolvedImageUrl;
                    $changed = true;
                }

                if ($changed) {
                    $community->verification_meta = array_merge(
                        is_array($community->verification_meta ?? null) ? $community->verification_meta : [],
                        [
                            'ingest' => [
                                'url_input' => $normalizedUrl,
                                'url_canonical' => $canonicalUrl,
                                'auto_verify' => $autoVerify,
                                'updated_at' => now()->toIso8601String(),
                                'resolved' => true,
                            ],
                        ]
                    );

                    $community->save();
                }
            }

            // link обновляем всегда, если есть отличия (url/external id)
            if ((string)$existingLink->url !== (string)$canonicalUrl) {
                $existingLink->url = $canonicalUrl;
                $changed = true;
            }
            if ($externalCommunityId !== null && (string)$existingLink->external_community_id !== (string)$externalCommunityId) {
                $existingLink->external_community_id = $externalCommunityId;
                $changed = true;
            }
            if ($changed) {
                $existingLink->save();
            }

            // auto_verify: enqueue
            $verifyOutbox = null;
            if ($autoVerify) {
                $verifyOutbox = $this->enqueueVerifyOutbox(
                    communityId: (int)$existingLink->community_id,
                    sources: [$sourceKey], // либо ['auto'], если хочешь "по приоритету"
                    limitPerSource: 30,
                    overwrite: false,
                    clearAggregator: false,
                    requestedByUserId: optional($request->user())->id,
                    metaSource: 'import:auto_verify'
                );
            }

            return response()->json([
                'community_id' => (int)$existingLink->community_id,
                'social_link_id' => (int)$existingLink->id,
                'status' => $changed ? 'ingest_updated' : 'ingest_exists',
                'external_community_id' => (string)($existingLink->external_community_id ?? ''),
                'url' => (string)$existingLink->url,
                'verify' => $verifyOutbox ? [
                    'status' => 'verify_' . (string)$verifyOutbox['status'],
                    'outbox_id' => $verifyOutbox['outbox_id'] ?? null,
                ] : null,
            ]);
        }

        [$community, $link] = DB::transaction(function () use (
            $normalizedUrl,
            $canonicalUrl,
            $host,
            $path,
            $autoVerify,
            $socialNetwork,
            $externalCommunityId,
            $resolvedName,
            $resolvedDescription,
            $resolvedAvatarUrl,
            $resolvedImageUrl
        ) {
            $community = new Community();
            $community->name = ($resolvedName && $resolvedName !== '') ? $resolvedName : $this->buildPlaceholderName($host, $path);
            $community->verification_status = 'pending';

            if ($resolvedDescription !== null) {
                $community->description = $resolvedDescription;
            }
            if ($resolvedAvatarUrl !== null) {
                $community->avatar_url = $resolvedAvatarUrl;
            }
            if ($resolvedImageUrl !== null) {
                $community->image_url = $resolvedImageUrl;
            }

            $community->verification_meta = array_merge(
                is_array($community->verification_meta ?? null) ? $community->verification_meta : [],
                [
                    'ingest' => [
                        'url_input' => $normalizedUrl,
                        'url_canonical' => $canonicalUrl,
                        'auto_verify' => $autoVerify,
                        'created_at' => now()->toIso8601String(),
                        'resolved' => (bool)($resolvedName && $resolvedName !== ''),
                    ],
                ]
            );

            $community->save();

            $link = new CommunitySocialLink();
            $link->community_id = $community->id;
            $link->social_network_id = $socialNetwork->id;
            $link->external_community_id = $externalCommunityId;
            $link->url = $canonicalUrl;
            $link->save();

            return [$community, $link];
        });

        // auto_verify: enqueue
        $verifyOutbox = null;
        if ($autoVerify) {
            $verifyOutbox = $this->enqueueVerifyOutbox(
                communityId: (int)$community->id,
                sources: [$sourceKey], // либо ['auto']
                limitPerSource: 30,
                overwrite: false,
                clearAggregator: false,
                requestedByUserId: optional($request->user())->id,
                metaSource: 'import:auto_verify'
            );
        }

        return response()->json([
            'community_id' => (int)$community->id,
            'social_link_id' => (int)$link->id,
            'status' => ($resolvedName && $resolvedName !== '') ? 'ingest_resolved' : 'ingest_created',
            'external_community_id' => (string)($link->external_community_id ?? ''),
            'url' => (string)$link->url,
            'verify' => $verifyOutbox ? [
                'status' => 'verify_' . (string)$verifyOutbox['status'],
                'outbox_id' => $verifyOutbox['outbox_id'] ?? null,
            ] : null,
        ], 201);
    }

    /**
     * POST /api/admin/communities/{id}/verify
     *
     * Запуск верификации ПО ЗАПРОСУ:
     * - Никаких VK запросов тут.
     * - Только outbox_messages → parser заберёт и запустит VerifyCommunityJob.
     */
    public function verify(AdminCommunitiesVerifyRequest $request, int $id): JsonResponse
    {
        $community = Community::withTrashed()->findOrFail($id);
        $validated = $request->validated();

        // доступные источники по ссылкам сообщества
        $available = CommunitySocialLink::query()
            ->join('social_networks', 'social_networks.id', '=', 'community_social_links.social_network_id')
            ->where('community_social_links.community_id', (int)$community->id)
            ->pluck('social_networks.slug')
            ->map(function ($slug) {
                $s = (string)$slug;
                if (in_array($s, ['vk'], true)) return 'vk';
                if (in_array($s, ['telegram', 'tg'], true)) return 'tg';
                return 'site';
            })
            ->unique()
            ->values()
            ->all();

        $requested = array_values($validated['sources'] ?? []);
        if (empty($requested)) {
            $sources = !empty($available) ? $available : ['vk'];
        } else {
            $sources = array_values(array_intersect($requested, $available));
            if (empty($sources)) {
                throw ValidationException::withMessages([
                    'sources' => ['Нет подходящих ссылок по параметру sources. Доступно: ' . implode(', ', $available ?: ['(нет ссылок)'])],
                ]);
            }
        }

        $result = $this->enqueueVerifyOutbox(
            communityId: (int)$community->id,
            sources: $sources,
            limitPerSource: (int)($validated['limit_per_source'] ?? 30),
            overwrite: (bool)($validated['overwrite'] ?? false),
            clearAggregator: (bool)($validated['clear_aggregator'] ?? false),
            requestedByUserId: optional($request->user())->id,
            metaSource: 'api'
        );

        return response()->json([
            'ok' => true,
            'status' => 'verify_' . (string)$result['status'],
            'outbox_id' => $result['outbox_id'] ?? null,
            'community_id' => (int)$community->id,
        ], 202);
    }

    private function parseUrlParts(string $url): array
    {
        $url = trim($url);

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            throw ValidationException::withMessages([
                'url' => ['Некорректный URL.'],
            ]);
        }

        $host = strtolower((string)$parts['host']);
        $host = preg_replace('~^www\.~', '', $host);

        $path = (string)($parts['path'] ?? '');
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        $query = (string)($parts['query'] ?? '');

        $normalizedUrl = 'https://' . $host . ($path === '/' ? '' : $path);

        return [$normalizedUrl, $host, $path, $query];
    }

    private function resolveSocialNetworkByHost(string $host): array
    {
        $host = strtolower($host);

        // VK: vk.com, vk.ru, vkontakte.ru (+ поддомены типа m.vk.com)
        if (Str::endsWith($host, 'vk.com') || Str::endsWith($host, 'vk.ru') || Str::endsWith($host, 'vkontakte.ru')) {
            $slugCandidates = ['vk'];
            $sourceKey = 'vk';
        } elseif ($host === 't.me' || $host === 'telegram.me') {
            $slugCandidates = ['telegram', 'tg'];
            $sourceKey = 'tg';
        } else {
            $slugCandidates = ['site', 'web'];
            $sourceKey = 'site';
        }

        $sn = SocialNetwork::query()
            ->whereIn('slug', $slugCandidates)
            ->first();

        if (!$sn) {
            throw ValidationException::withMessages([
                'url' => ['Не найдена соцсеть в справочнике social_networks (slug: ' . implode(',', $slugCandidates) . ').'],
            ]);
        }

        return [$sourceKey, $sn];
    }

    private function extractExternalCommunityId(string $sourceKey, string $path, string $query): ?string
    {
        if ($sourceKey === 'vk') {
            // screen-name: /vinzavodpro, /some_group (самый частый кейс)
            $p = ltrim($path, '/');
            $seg = trim((string)(explode('/', $p, 2)[0] ?? ''));
            if ($seg !== '') {
                // canonical формы: /club123 /public123 /event123
                if (preg_match('~^(?:club|public|event)(\d+)$~', $seg, $m)) {
                    return (string)$m[1];
                }
                // иногда бывает /id123
                if (preg_match('~^id(\d+)$~', $seg, $m)) {
                    return (string)$m[1];
                }
            }

            if (preg_match('~/(?:club|public|event)(\d+)$~', $path, $m)) {
                return (string)$m[1];
            }

            if (preg_match('~wall-(\d+)~', $path, $m)) {
                return (string)$m[1];
            }

            if ($query) {
                parse_str($query, $qs);
                $w = (string)($qs['w'] ?? '');
                if ($w && preg_match('~wall-(\d+)_~', $w, $m)) {
                    return (string)$m[1];
                }
            }

            // если это /vinzavodpro — вернём seg (string), чтобы дальше не было NULL
            return $seg !== '' ? $seg : null;
        }

        if ($sourceKey === 'tg') {
            $p = ltrim($path, '/');

            if (Str::startsWith($p, 's/')) {
                $p = substr($p, 2);
            }

            if ($p === '' || Str::startsWith($p, 'joinchat/') || Str::startsWith($p, '+')) {
                return null;
            }

            $seg = explode('/', $p)[0] ?? '';
            $seg = trim($seg);

            return $seg !== '' ? $seg : null;
        }

        return null;
    }

    private function buildPlaceholderName(string $host, string $path): string
    {
        $p = trim($path, '/');
        $base = $host . ($p !== '' ? '/' . $p : '');
        return Str::limit('Импорт: ' . $base, 255, '…');
    }

    private function enqueueVerifyOutbox(
        int $communityId,
        array $sources,
        int $limitPerSource,
        bool $overwrite,
        bool $clearAggregator,
        ?int $requestedByUserId,
        string $metaSource
    ): array {
        $topic = 'community.verify.auto';
        $dedupKey = 'community:' . $communityId;

        $payload = [
            'community_id' => $communityId,
            'sources' => array_values($sources),
            'limit_per_source' => $limitPerSource,
            'overwrite' => $overwrite,
            'clear_aggregator' => $clearAggregator,
            'requested_at' => now()->toIso8601String(),
            'requested_by_user_id' => $requestedByUserId,
        ];

        try {
            $outboxId = DB::table('outbox_messages')->insertGetId([
                'producer' => 'kudab-api',
                'topic' => $topic,
                'dedup_key' => $dedupKey,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'queued',
                'attempt' => 0,
                'max_attempts' => 10,
                'retry_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'locked_at' => null,
                'locked_by' => null,
                'error_code' => null,
                'error_message' => null,
                'meta' => json_encode(['source' => $metaSource], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => 'queued', 'outbox_id' => (int)$outboxId];
        } catch (QueryException $e) {
            $sqlState = (string)($e->errorInfo[0] ?? '');
            if ($sqlState !== '23505') {
                throw $e;
            }
        }

        $existing = DB::table('outbox_messages')
            ->where('topic', $topic)
            ->where('dedup_key', $dedupKey)
            ->first();

        if (!$existing) {
            return ['status' => 'dedup_conflict_not_found', 'outbox_id' => null];
        }

        if (in_array((string)$existing->status, ['queued', 'processing'], true)) {
            return ['status' => 'already_queued', 'outbox_id' => (int)$existing->id];
        }

        DB::table('outbox_messages')
            ->where('id', (int)$existing->id)
            ->update([
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'queued',
                'attempt' => 0,
                'retry_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'locked_at' => null,
                'locked_by' => null,
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);

        return ['status' => 'requeued', 'outbox_id' => (int)$existing->id];
    }
}
