<?php

namespace App\Services\Vk;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class VkCommunityResolver
{
    private string $accessToken;
    private string $apiVersion;

    public function __construct()
    {
        $this->accessToken = (string) config('services.vk.token');
        $this->apiVersion  = (string) config('services.vk.version');

        if ($this->accessToken === '' || $this->apiVersion === '') {
            throw new \RuntimeException('VK config is missing: services.vk.token / services.vk.version');
        }
    }

    /**
     * Resolve VK community by URL or short handle.
     *
     * Returns:
     * - external_community_id (string)
     * - name (string)
     * - description (string|null)
     * - screen_name (string|null)
     * - canonical_url (string)
     * - avatar_url (string|null)
     * - image_url (string|null)
     */
    public function resolve(string $input): array
    {
        $canonicalInputUrl = $this->canonicalizeVkUrl($input);

        $screenNameOrId = $this->extractScreenNameOrId($canonicalInputUrl);
        if ($screenNameOrId === '') {
            throw new \RuntimeException('VK url has empty screen_name.');
        }

        // 1) Пробуем сразу вытащить числовой id из:
        // - wall-123_456
        // - club123/public123/event123/123
        $numericGroupId = $this->extractNumericGroupId($screenNameOrId);

        // 2) Если не получилось — resolveScreenName
        if ($numericGroupId === null) {
            $resolved = $this->vkMethod('utils.resolveScreenName', [
                'screen_name' => $screenNameOrId,
            ]);

            $objectId = (string) ($resolved['object_id'] ?? '');
            $type     = (string) ($resolved['type'] ?? '');

            if ($objectId === '' || !ctype_digit($objectId)) {
                throw new \RuntimeException('VK resolveScreenName returned invalid object_id.');
            }

            // user нам тут не подходит
            if (!in_array($type, ['group', 'page', 'event'], true)) {
                throw new \RuntimeException("VK resolveScreenName returned unsupported type: {$type}");
            }

            $numericGroupId = $objectId;
        }

        // 3) groups.getById → профиль группы
        $group = $this->getGroupById($numericGroupId);

        $name = trim((string)($group['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException(
                'VK group resolved, but name is empty. Group payload: ' .
                json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $screenName = isset($group['screen_name']) && trim((string)$group['screen_name']) !== ''
            ? trim((string)$group['screen_name'])
            : null;

        $description = isset($group['description'])
            ? trim((string)$group['description'])
            : '';
        $description = $description !== '' ? $description : null;

        $avatarUrl = $this->pickBestAvatarUrl($group);
        $imageUrl  = $this->pickBestCoverUrl($group);

        $canonicalGroupUrl = $screenName
            ? ('https://vk.com/' . $screenName)
            : ('https://vk.com/club' . $numericGroupId);

        return [
            'external_community_id' => (string) $numericGroupId,
            'name'                  => $name,
            'description'           => $description,
            'screen_name'           => $screenName,
            'canonical_url'         => $canonicalGroupUrl,
            'avatar_url'            => $avatarUrl,
            'image_url'             => $imageUrl,
        ];
    }

    private function getGroupById(string $groupId): array
    {
        $response = $this->vkMethod('groups.getById', [
            'group_id' => $groupId,
            'fields' => implode(',', [
                'screen_name',
                'description',
                'photo_50',
                'photo_100',
                'photo_200',
                'photo_200_orig',
                'photo_400_orig',
                'photo_max',
                'photo_max_orig',
                'cover',
                'site',
                'status',
                'members_count',
                'city',
            ]),
        ]);

        // Формат A: response = [ {id, name, ...} ]
        if (is_array($response) && isset($response[0]) && is_array($response[0])) {
            return $response[0];
        }

        // Формат B: response = { groups: [ {id, name, ...} ], profiles: [] }
        if (is_array($response) && isset($response['groups'][0]) && is_array($response['groups'][0])) {
            return $response['groups'][0];
        }

        // На всякий: если вдруг VK вернул одну группу плоско
        if (is_array($response) && isset($response['id'])) {
            return $response;
        }

        return [];
    }

    private function pickBestAvatarUrl(array $group): ?string
    {
        $candidates = [
            $group['photo_max_orig'] ?? null,
            $group['photo_400_orig'] ?? null,
            $group['photo_max'] ?? null,
            $group['photo_200_orig'] ?? null,
            $group['photo_200'] ?? null,
            $group['photo_100'] ?? null,
            $group['photo_50'] ?? null,
        ];

        foreach ($candidates as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function pickBestCoverUrl(array $group): ?string
    {
        // 1) cover.images[] (берём самый большой по width)
        if (isset($group['cover']['images']) && is_array($group['cover']['images'])) {
            $bestUrl = null;
            $bestWidth = -1;

            foreach ($group['cover']['images'] as $img) {
                if (!is_array($img)) {
                    continue;
                }

                $url = isset($img['url']) && is_string($img['url']) ? trim($img['url']) : '';
                $w   = isset($img['width']) ? (int)$img['width'] : 0;

                if ($url !== '' && $w > $bestWidth) {
                    $bestWidth = $w;
                    $bestUrl = $url;
                }
            }

            if ($bestUrl) {
                return $bestUrl;
            }
        }

        // 2) fallback: photo_max_orig/photo_max/...
        $fallback = [
            $group['photo_max_orig'] ?? null,
            $group['photo_max'] ?? null,
            $group['photo_400_orig'] ?? null,
            $group['photo_200_orig'] ?? null,
        ];

        foreach ($fallback as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function vkMethod(string $method, array $params): array
    {
        /** @var Response $httpResponse */
        $httpResponse = Http::retry(2, 200)
            ->timeout(12)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'kudab-api/1.0',
            ])
            ->get(
                "https://api.vk.com/method/{$method}",
                array_merge($params, [
                    'access_token' => $this->accessToken,
                    'v'            => $this->apiVersion,
                ])
            );

        if (!$httpResponse->ok()) {
            throw new \RuntimeException("VK API {$method}: HTTP {$httpResponse->status()}");
        }

        $json = $httpResponse->json();
        if (!is_array($json)) {
            throw new \RuntimeException("VK API {$method}: invalid JSON response");
        }

        if (isset($json['error'])) {
            $message = (string)($json['error']['error_msg'] ?? 'VK API error');
            $code    = (int)($json['error']['error_code'] ?? 0);
            throw new \RuntimeException("VK API {$method}: {$message}", $code);
        }

        return is_array($json['response'] ?? null) ? $json['response'] : [];
    }

    private function canonicalizeVkUrl(string $input): string
    {
        $trimmed = trim($input);

        // разрешаем "club1" / "vk.com/club1"
        if (!preg_match('~^https?://~i', $trimmed)) {
            $trimmed = 'https://vk.com/' . ltrim($trimmed, '/');
        }

        // нормализуем домены
        $trimmed = preg_replace('~^https?://m\.vk\.com~i', 'https://vk.com', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('~^https?://vk\.ru~i', 'https://vk.com', $trimmed) ?? $trimmed;

        // режем query/fragment → канонический инпут для парсинга
        $parts = parse_url($trimmed);
        $host = isset($parts['host']) ? strtolower((string)$parts['host']) : 'vk.com';
        $host = preg_replace('~^www\.~', '', $host) ?? $host;

        $path = isset($parts['path']) ? '/' . ltrim((string)$parts['path'], '/') : '/';
        $path = rtrim($path, '/');

        // "/" -> без хвоста
        $pathForUrl = ($path === '/' ? '' : $path);

        return 'https://' . $host . $pathForUrl;
    }

    private function extractScreenNameOrId(string $url): string
    {
        $parts = parse_url($url);

        $path = isset($parts['path']) ? trim((string)$parts['path'], '/') : '';
        if ($path === '') {
            return '';
        }

        // берём первый сегмент пути
        $firstSegment = explode('/', $path, 2)[0];
        $firstSegment = ltrim($firstSegment, '@');

        return trim($firstSegment);
    }

    private function extractNumericGroupId(string $screenNameOrId): ?string
    {
        $screenNameOrId = trim($screenNameOrId);

        // wall-123_456 / wall-123
        if (preg_match('~^wall-(\d+)(?:_|$)~i', $screenNameOrId, $m)) {
            return (string)$m[1];
        }

        // club123 / public123 / event123
        if (preg_match('~^(club|public|event)(\d+)$~i', $screenNameOrId, $m)) {
            return (string)$m[2];
        }

        // просто "123"
        if ($screenNameOrId !== '' && ctype_digit($screenNameOrId)) {
            return $screenNameOrId;
        }

        return null;
    }
}
