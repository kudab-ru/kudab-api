<?php

declare(strict_types=1);

namespace App\Services\Sources;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Лёгкая разведка раздела Я.Афиши «сейчас» для админки: один headless-fetch
 * листинга https://afisha.yandex.ru/{city}/{section} → подсчёт event-URL'ов
 * regex'ом. БЕЗ detail-страниц — это быстрый (~5–15с) preview, можно гонять
 * синхронно в HTTP-цикле (в отличие от полного сбора), поэтому очередь/тики
 * парсера не нужны: api дёргает kudab-headless напрямую.
 *
 * Используется суперадмином, чтобы проверить кастомный slug раздела ДО включения
 * (0 URL = slug неверный/пустой). Read-only, ничего не пишет в БД.
 */
final class YandexAfishaScanner
{
    /** Realistic Chrome — дефолтный KudabBot/1.0 Я.Афиша выборочно банит. */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    /**
     * @return array{ok: bool, urls_found: int, sample: list<string>, error: ?string}
     */
    public function scan(string $city, string $section): array
    {
        $listingUrl = "https://afisha.yandex.ru/{$city}/{$section}";
        $endpoint = (string) env('HEADLESS_ENDPOINT', 'http://kudab-headless:8080/render');
        $renderTimeoutMs = (int) env('YANDEX_AFISHA_LISTING_TIMEOUT_MS', 20000);

        try {
            $resp = Http::timeout((int) ceil($renderTimeoutMs / 1000) + 10)
                ->connectTimeout(3)
                ->acceptJson()
                ->post($endpoint, [
                    'url' => $listingUrl,
                    'wait_for' => 'selector',
                    'wait_selector' => 'a[href*="/'.$city.'/'.$section.'/"]',
                    'timeout_ms' => $renderTimeoutMs,
                    'user_agent' => self::USER_AGENT,
                ]);
        } catch (Throwable $e) {
            Log::info('admin:yandex-scan:transport-fail', ['url' => $listingUrl, 'err' => $e->getMessage()]);

            return $this->fail('Не удалось достучаться до headless-сервиса.');
        }

        $data = $resp->json();
        if (! is_array($data) || ($data['status'] ?? null) !== 'ok' || ! is_string($data['html'] ?? null)) {
            // Чаще всего — таймаут wait_selector: раздела нет или он пуст.
            return $this->fail('Листинг не отрендерился (раздел пуст, неверный slug или таймаут).');
        }

        $paths = $this->extractEventPaths($data['html'], $city, $section);

        return [
            'ok' => true,
            'urls_found' => count($paths),
            'sample' => array_slice(array_map(static fn (string $p) => basename($p), $paths), 0, 3),
            'error' => null,
        ];
    }

    /**
     * @return list<string> Уникальные пути event-страниц раздела.
     */
    private function extractEventPaths(string $html, string $city, string $section): array
    {
        $cityRe = preg_quote($city, '~');
        $secRe = preg_quote($section, '~');
        $pattern = '~href=["\'](/'.$cityRe.'/'.$secRe.'/[a-z0-9][a-z0-9-]*)["\'?#]~i';
        if (! preg_match_all($pattern, $html, $m)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $p) => rtrim($p, '/'),
            $m[1],
        )));
    }

    /**
     * @return array{ok: bool, urls_found: int, sample: list<string>, error: string}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'urls_found' => 0, 'sample' => [], 'error' => $error];
    }
}
