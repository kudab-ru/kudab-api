<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Communities\AdminCommunitiesImportRequest;
use App\Http\Requests\Admin\Communities\AdminCommunitiesIndexRequest;
use App\Http\Requests\Admin\Communities\AdminCommunitiesStoreRequest;
use App\Http\Requests\Admin\Communities\AdminCommunitiesUpdateRequest;
use App\Http\Resources\Admin\CommunityResource as AdminCommunityResource;
use App\Models\Community;
use App\Models\CommunitySocialLink;
use App\Models\SocialNetwork;
use App\Services\Vk\VkCommunityResolver;
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
     * VK: синхронный online resolve + запись профиля (name/description/avatar/cover) в communities.
     * Дубли: обновляем (перезаписываем) community и link для удобного "обновления импорта".
     */
    public function import(AdminCommunitiesImportRequest $request, VkCommunityResolver $vkResolver): JsonResponse
    {
        $validated = $request->validated();

        $inputUrl = trim((string)$validated['url']);
        $shouldAutoVerify = (bool)($validated['auto_verify'] ?? false);

        [$normalizedInputUrl, $host, $path, $query] = $this->parseUrlParts($inputUrl);
        [$sourceKey, $socialNetwork] = $this->resolveSocialNetworkByHost($host);

        // оффлайн-попытка (tg и спец vk ссылки)
        $externalCommunityId = $this->extractExternalCommunityId($sourceKey, $path, $query);

        $canonicalUrl = $normalizedInputUrl;

        $resolvedName = null;
        $resolvedDescription = null;
        $resolvedAvatarUrl = null;
        $resolvedImageUrl = null;

        if ($sourceKey === 'vk') {
            if ((string)config('services.vk.token') === '' || (string)config('services.vk.version') === '') {
                throw ValidationException::withMessages([
                    'url' => ['VK is not configured (services.vk.token / services.vk.version).'],
                ]);
            }

            try {
                $resolved = $vkResolver->resolve($normalizedInputUrl);

                $externalCommunityId = trim((string)($resolved['external_community_id'] ?? ''));
                $resolvedName = trim((string)($resolved['name'] ?? ''));

                if ($externalCommunityId === '' || $resolvedName === '') {
                    throw new \RuntimeException('VK resolver returned empty external_community_id or name.');
                }

                $canonicalUrl = (string)($resolved['canonical_url'] ?? $canonicalUrl);
                $resolvedDescription = $resolved['description'] ?? null;
                $resolvedAvatarUrl = $resolved['avatar_url'] ?? null;
                $resolvedImageUrl = $resolved['image_url'] ?? null;
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    'url' => ['VK resolve failed: ' . $e->getMessage()],
                ]);
            }
        }

        // Дедуп: по canonical url, и (если есть) по (social_network_id + external_community_id)
        $existingLink = CommunitySocialLink::query()
            ->where('url', $canonicalUrl)
            ->when($externalCommunityId !== null && $externalCommunityId !== '', function ($q) use ($socialNetwork, $externalCommunityId) {
                $q->orWhere(function ($qq) use ($socialNetwork, $externalCommunityId) {
                    $qq->where('social_network_id', $socialNetwork->id)
                        ->where('external_community_id', $externalCommunityId);
                });
            })
            ->first();

        if ($existingLink) {
            DB::transaction(function () use (
                $existingLink,
                $normalizedInputUrl,
                $canonicalUrl,
                $externalCommunityId,
                $resolvedName,
                $resolvedDescription,
                $resolvedAvatarUrl,
                $resolvedImageUrl,
                $shouldAutoVerify
            ) {
                // обновляем ссылку
                $linkChanged = false;

                if ($existingLink->url !== $canonicalUrl) {
                    $existingLink->url = $canonicalUrl;
                    $linkChanged = true;
                }

                if ($externalCommunityId !== null && $externalCommunityId !== '' &&
                    (string)$existingLink->external_community_id !== (string)$externalCommunityId
                ) {
                    $existingLink->external_community_id = $externalCommunityId;
                    $linkChanged = true;
                }

                if ($linkChanged) {
                    $existingLink->save();
                }

                // перезаписываем профиль (как ты хотел)
                if ($resolvedName !== null && $resolvedName !== '') {
                    $community = $existingLink->community;
                    if ($community) {
                        $community->name = $resolvedName;
                        $community->description = $resolvedDescription;
                        $community->avatar_url = $resolvedAvatarUrl;
                        $community->image_url = $resolvedImageUrl;

                        $meta = is_array($community->verification_meta ?? null) ? $community->verification_meta : [];
                        $meta['ingest'] = array_merge($meta['ingest'] ?? [], [
                            'url_input' => $normalizedInputUrl,
                            'url_canonical' => $canonicalUrl,
                            'auto_verify' => $shouldAutoVerify,
                            'resolved' => true,
                            'updated_at' => now()->toIso8601String(),
                        ]);
                        $community->verification_meta = $meta;

                        $community->save();
                    }
                }

                // TODO: если shouldAutoVerify=true — добавь твой outbox insert (если хочешь)
            });

            return response()->json([
                'community_id' => (int)$existingLink->community_id,
                'social_link_id' => (int)$existingLink->id,
                'status' => ($resolvedName ? 'ingest_updated' : 'ingest_exists'),
                'external_community_id' => $existingLink->external_community_id,
                'url' => $existingLink->url,
            ], 200);
        }

        [$community, $link] = DB::transaction(function () use (
            $normalizedInputUrl,
            $canonicalUrl,
            $host,
            $path,
            $socialNetwork,
            $externalCommunityId,
            $resolvedName,
            $resolvedDescription,
            $resolvedAvatarUrl,
            $resolvedImageUrl,
            $shouldAutoVerify
        ) {
            $community = new Community();

            $community->name = ($resolvedName && $resolvedName !== '')
                ? $resolvedName
                : $this->buildPlaceholderName($host, $path);

            $community->description = $resolvedDescription;
            $community->avatar_url = $resolvedAvatarUrl;
            $community->image_url = $resolvedImageUrl;

            $community->verification_status = 'pending';

            $community->verification_meta = array_merge(
                is_array($community->verification_meta ?? null) ? $community->verification_meta : [],
                [
                    'ingest' => [
                        'url_input' => $normalizedInputUrl,
                        'url_canonical' => $canonicalUrl,
                        'auto_verify' => $shouldAutoVerify,
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

            // TODO: если shouldAutoVerify=true — добавь твой outbox insert (если хочешь)

            return [$community, $link];
        });

        return response()->json([
            'community_id' => (int)$community->id,
            'social_link_id' => (int)$link->id,
            'status' => ($resolvedName ? 'ingest_resolved' : 'ingest_created'),
            'name' => $community->name,
            'external_community_id' => $link->external_community_id,
            'url' => $link->url,
        ], 201);
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
        $host = preg_replace('~^www\.~', '', $host) ?? $host;

        $path = (string)($parts['path'] ?? '');
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $query = (string)($parts['query'] ?? '');

        $normalizedUrl = 'https://' . $host . ($path === '/' ? '' : $path);

        return [$normalizedUrl, $host, $path, $query];
    }

    private function resolveSocialNetworkByHost(string $host): array
    {
        $host = strtolower($host);

        if (Str::endsWith($host, 'vk.com') || Str::endsWith($host, 'vkontakte.ru')) {
            $slugCandidates = ['vk'];
            $sourceKey = 'vk';
        } elseif ($host === 't.me' || $host === 'telegram.me') {
            $slugCandidates = ['telegram', 'tg'];
            $sourceKey = 'tg';
        } else {
            $slugCandidates = ['site', 'web'];
            $sourceKey = 'site';
        }

        $socialNetwork = SocialNetwork::query()
            ->whereIn('slug', $slugCandidates)
            ->first();

        if (!$socialNetwork) {
            throw ValidationException::withMessages([
                'url' => ['Не найдена соцсеть в social_networks (slug: ' . implode(',', $slugCandidates) . ').'],
            ]);
        }

        return [$sourceKey, $socialNetwork];
    }

    private function extractExternalCommunityId(string $sourceKey, string $path, string $query): ?string
    {
        if ($sourceKey === 'vk') {
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

            return null;
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
}
