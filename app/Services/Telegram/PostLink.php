<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Метка на все ссылки поста на kudab.ru: без неё CollectClicksCommand переход не посчитает.
 * utm_content = i<id записи очереди>, не события, чтобы ссылки поста складывались в одно число.
 */
final class PostLink
{
    public const MEDIUM_EVENT = 'post';

    public const MEDIUM_DIGEST = 'digest';

    public const MEDIUM_VENUE = 'venue';

    /**
     * @param  int|null  $itemId  запись очереди; null — пост собирают не для
     *                            канала (превью шаблона), и мерить там нечего
     */
    public static function utm(string $url, string $medium, ?int $itemId): string
    {
        if ($itemId === null || $url === '') {
            return $url;
        }

        $source = (string) (config('broadcast_digest.utm.source') ?: 'tg');
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'utm_source='.$source.'&utm_medium='.$medium.'&utm_content=i'.$itemId;
    }
}
