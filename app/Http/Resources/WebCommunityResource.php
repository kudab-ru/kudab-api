<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Публичная карточка сообщества-источника для страницы /sources
 * (`GET /web/communities`).
 *
 * ВАЖНО: это НЕ Admin\CommunityResource — тот публично утёк бы
 * verification_status / verification_meta / deleted_at. Здесь только
 * то, что уместно показать посетителю: имя, аватар, город, число
 * событий в афише и прямые ссылки на первоисточник (VK/TG/сайт).
 *
 * `events_count` приходит через withCount(['events as events_count' => visibility])
 * в CommunitiesController (та же видимость, что у веб-ленты).
 * `links` — eager-loaded socialLinks (без status='black') + socialNetwork.
 */
class WebCommunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => (int) $this->id,
            'name'         => (string) ($this->name ?? ''),
            'avatar_url'   => $this->avatar_url ?: null,
            'city'         => $this->whenLoaded('city', function () {
                return $this->city
                    ? [
                        'slug' => (string) ($this->city->slug ?? ''),
                        'name' => (string) ($this->city->name ?? ''),
                    ]
                    : null;
            }),
            'events_count' => (int) ($this->getAttribute('events_count') ?? 0),
            'links'        => $this->whenLoaded('socialLinks', function () {
                return $this->socialLinks
                    ->map(function ($link) {
                        $url = trim((string) ($link->url ?? ''));
                        if ($url === '') {
                            return null;
                        }

                        return [
                            'kind' => self::mapKind($link->socialNetwork->slug ?? null),
                            'url'  => $url,
                        ];
                    })
                    ->filter()
                    ->values();
            }),
        ];
    }

    /**
     * DB-слаг соцсети → тип для UI. В БД слаги vk / telegram / site
     * (SocialNetworksTableSeeder), а UI-бейджи оперируют vk / tg / web.
     */
    private static function mapKind(?string $slug): string
    {
        return match ($slug) {
            'vk'       => 'vk',
            'telegram' => 'tg',
            default    => 'web',
        };
    }
}
