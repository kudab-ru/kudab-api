<?php

declare(strict_types=1);

namespace App\Services\Kulisy;

use App\Models\Venue;
use App\Models\VenueManager;
use App\Services\Vk\VkCommunityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Подтверждение площадки: организатор вставляет код в описание группы ВК площадки,
 * мы читаем описание через API ВК и ищем код.
 */
final class VenueClaims
{
    // без похожих знаков: 0 и O, 1 и I путают, когда переписывают код руками
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function code(): string
    {
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'kudab-'.$out;
    }

    /**
     * Группы ВК, которыми можно подтвердить площадку: сообщество привязано к ней или больше
     * половины его событий проходят здесь. Городская афиша сюда не попадает, иначе её админ
     * подтвердил бы любую площадку, о которой она пишет.
     *
     * @return list<string>
     */
    public function groupsFor(Venue $venue): array
    {
        $rows = DB::select(<<<'SQL'
            select l.url
            from communities c
            join community_social_links l on l.community_id = c.id
            join social_networks n on n.id = l.social_network_id and n.slug = 'vk'
            left join (
                select community_id, count(*) filter (where venue_id = :here) as here, count(*) as total
                from events
                where community_id in (select community_id from events where venue_id = :venue)
                group by community_id
            ) s on s.community_id = c.id
            where c.deleted_at is null and (c.venue_id = :bound or s.here * 2 > s.total)
            order by coalesce(s.here, 0) desc
            limit 3
            SQL, ['here' => $venue->id, 'venue' => $venue->id, 'bound' => $venue->id]);

        return array_map(fn ($r) => (string) $r->url, $rows);
    }

    /**
     * @return 'confirmed'|'not_found'|'no_group'|'vk_failed'
     */
    public function check(VenueManager $claim): string
    {
        $groups = $this->groupsFor($claim->venue);
        if ($groups === []) {
            return 'no_group';
        }

        $failed = false;
        foreach ($groups as $url) {
            try {
                $description = (string) (app(VkCommunityResolver::class)->resolve($url)['description'] ?? '');
            } catch (\Throwable) {
                $failed = true;

                continue;
            }
            if (Str::contains(Str::lower($description), Str::lower($claim->code))) {
                $claim->forceFill(['status' => 'confirmed', 'confirmed_at' => now(), 'vk_url' => $url])->save();

                return 'confirmed';
            }
        }

        return $failed ? 'vk_failed' : 'not_found';
    }
}
