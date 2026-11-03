<?php

declare(strict_types=1);

namespace App\Support;

/** Кассы, где страница события и есть покупка билета. Тот же список у сайта в `shared/lib/ticketHref.ts`. */
final class TicketOffices
{
    /** Для SQL (`~*`) и для `preg_match`. */
    public const PATTERN = '^https?://([^/]*\\.)?(afisha\\.yandex\\.ru|bezantrakta\\.ru|kassir\\.ru|qtickets\\.events|live\\.mts\\.ru|quicktickets\\.ru)(/|$)';

    /** Куда вести за билетом: своя ссылка на билеты, без неё страница кассы, откуда событие взято; пусто — некуда. */
    public static function url(?string $priceUrl, ?string $externalUrl): string
    {
        if ((string) $priceUrl !== '') {
            return (string) $priceUrl;
        }
        $external = (string) $externalUrl;

        return $external !== '' && preg_match('#'.self::PATTERN.'#i', $external) === 1 ? $external : '';
    }
}
