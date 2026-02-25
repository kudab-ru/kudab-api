<?php

namespace App\Services\Url;

final class SocialNetworkSourceMapper
{
    public function sourceFromSlug(?string $slug): string
    {
        $s = strtolower(trim((string)$slug));

        if ($s === 'vk') return 'vk';
        if ($s === 'telegram' || $s === 'tg') return 'tg';

        return 'site';
    }
}
