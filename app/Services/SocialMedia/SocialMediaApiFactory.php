<?php

namespace App\Services\SocialMedia;

use App\Contracts\SocialMediaServiceInterface;

class SocialMediaApiFactory
{
    /**
     * Вернуть сервис по ID соцсети.
     * Для пока неподдержанных источников — NullSocialService (чтобы пометить "skipped").
     */
    public static function getServiceBySocialNetworkId(int|string $socialNetworkId): SocialMediaServiceInterface
    {
//        return match ((int) $socialNetworkId) {
//            1       => new VkApiService,                 // vk
//            2       => new NullSocialService('telegram'),// TODO: TelegramAdapter
//            3       => new NullSocialService('site'),    // TODO: SiteAdapter
//            default => new NullSocialService('unknown'),
//        };
    }

    /**
     * Вернуть сервис по slug.
     */
    public static function getServiceBySlug(string $slug): SocialMediaServiceInterface
    {
        $slug = strtolower(trim($slug));
        if ($slug === 'tg') $slug = 'telegram';

//        return match ($slug) {
//            'vk'       => new VkApiService,
//            'telegram' => new NullSocialService('telegram'), // TODO
//            'site'     => new NullSocialService('site'),     // TODO
//            default    => new NullSocialService('unknown'),
//        };
    }
}
