<?php

namespace App\Services\Url;

use Illuminate\Support\Str;

final class UrlClassifier
{
    public function classify(string $inputUrl): UrlClassification
    {
        $inputUrl = trim($inputUrl);

        [$normalizedUrl, $host, $path, $query] = $this->parseUrlParts($inputUrl);

        [$sourceKey, $slugCandidates] = $this->resolveSourceByHost($host);

        $externalId = $this->extractExternalCommunityId($sourceKey, $path, $query);

        $warnings = [];
        if ($sourceKey !== 'site' && ($externalId === null || trim($externalId) === '')) {
            $warnings[] = 'external_community_id is NULL';
        }

        return new UrlClassification(
            inputUrl: $inputUrl,
            normalizedUrl: $normalizedUrl,
            host: $host,
            path: $path,
            query: $query,
            sourceKey: $sourceKey,
            slugCandidates: $slugCandidates,
            externalCommunityId: $externalId,
            warnings: $warnings
        );
    }

    private function parseUrlParts(string $url): array
    {
        $url = trim($url);

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $host = preg_replace('~^www\.~', '', $host);

        $path = (string)($parts['path'] ?? '');
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        $query = (string)($parts['query'] ?? '');

        $normalizedUrl = 'https://' . $host . ($path === '/' ? '' : $path);

        return [$normalizedUrl, $host, $path, $query];
    }

    private function resolveSourceByHost(string $host): array
    {
        $host = strtolower($host);

        // VK: vk.com, vk.ru, vkontakte.ru (+ поддомены типа m.vk.com)
        if (Str::endsWith($host, 'vk.com') || Str::endsWith($host, 'vk.ru') || Str::endsWith($host, 'vkontakte.ru')) {
            return ['vk', ['vk']];
        }

        // TG
        if ($host === 't.me' || $host === 'telegram.me') {
            return ['tg', ['telegram', 'tg']];
        }

        return ['site', ['site', 'web']];
    }

    private function extractExternalCommunityId(string $sourceKey, string $path, string $query): ?string
    {
        if ($sourceKey === 'vk') {
            // screen-name: /vinzavodpro
            $p = ltrim($path, '/');
            $seg = trim((string)(explode('/', $p, 2)[0] ?? ''));

            if ($seg !== '') {
                if (preg_match('~^(?:club|public|event)(\d+)$~', $seg, $m)) return (string)$m[1];
                if (preg_match('~^id(\d+)$~', $seg, $m)) return (string)$m[1];
            }

            if (preg_match('~/(?:club|public|event)(\d+)$~', $path, $m)) return (string)$m[1];
            if (preg_match('~wall-(\d+)~', $path, $m)) return (string)$m[1];

            if ($query) {
                parse_str($query, $qs);
                $w = (string)($qs['w'] ?? '');
                if ($w && preg_match('~wall-(\d+)_~', $w, $m)) return (string)$m[1];
            }

            return $seg !== '' ? $seg : null;
        }

        if ($sourceKey === 'tg') {
            $p = ltrim($path, '/');
            if (Str::startsWith($p, 's/')) $p = substr($p, 2);

            if ($p === '' || Str::startsWith($p, 'joinchat/') || Str::startsWith($p, '+')) return null;

            $seg = trim((string)(explode('/', $p)[0] ?? ''));
            return $seg !== '' ? $seg : null;
        }

        return null;
    }
}
