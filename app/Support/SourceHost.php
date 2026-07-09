<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Канонизация хоста/URL источника-сайта. Один сайт = один источник по
 * origin-host (site.ru/afisha и site.ru/concerts — один хост). Живёт в
 * kudab-api (владелец записи source_profiles / source_probe_requests).
 *
 * Заменяет разъехавшиеся ad-hoc нормализации в AdminSourceProbeController,
 * включая баг `ltrim($host, 'w.')`, который трактовал 'w.' как НАБОР символов
 * и резал ведущие w/точки ('weekend.ru' → 'eekend.ru').
 */
final class SourceHost
{
    /**
     * Канонический origin-host: нижний регистр, без ведущего www[\d]*.,
     * без порта. 'https://WWW.Site.RU:443/afisha?x=1' → 'site.ru'.
     */
    public static function host(string $url): string
    {
        $host = (string) (parse_url(self::ensureScheme($url))['host'] ?? '');
        if ($host === '') {
            return '';
        }
        $host = mb_strtolower($host);

        return preg_replace('~^www\d*\.~', '', $host) ?? $host;
    }

    /**
     * Канон для сравнения/дедупа заявок по хосту: схема → https, canonical
     * host, без query/#/порта, path откинут. Две заявки на один сайт дают
     * одинаковый канон независимо от http/https, www, регистра, раздела.
     */
    public static function canonical(string $url): string
    {
        $host = self::host($url);

        return $host === '' ? rtrim($url, '/') : 'https://'.$host;
    }

    private static function ensureScheme(string $url): string
    {
        return preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) === 1
            ? $url
            : 'https://'.ltrim($url, '/');
    }
}
