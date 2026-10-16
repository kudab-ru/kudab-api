<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Похож ли запрос на живого человека: поисковые роботы, сборщики превью ссылок и
 * автоматические браузеры (в том числе наши снимки страниц) пишут себя в подписи браузера.
 */
final class HumanAgent
{
    /** «telegram» сюда не входит: встроенный браузер Телеграма тоже подписывается им. */
    private const NOT_A_PERSON = '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|phantom|puppeteer|playwright|selenium|python|curl|wget|okhttp|go-http|axios|node-fetch|scrapy/i';

    public static function looksLikePerson(?string $agent): bool
    {
        $agent = (string) $agent;

        return $agent !== '' && ! preg_match(self::NOT_A_PERSON, $agent);
    }
}
