<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Http;

/**
 * Тонкий клиент отчётов Яндекс.Метрики.
 *
 * ФИЛЬТР СЛУЖЕБНОГО ТРАФИКА ВШИТ СЮДА, а не приходит параметром. Наш
 * headless-браузер (Метрика опознаёт его как браузер «204») дал 296 визитов из
 * 1320 за месяц, и 201 из них — за один день 17.09, когда агент обходил сайт и
 * забыл заблокировать mc.yandex.ru. Пока фильтр был необязательным, любая
 * забытая строчка в вызове превращала отчёт о росте в отчёт о нашей же работе.
 * Параметр, который можно не передать, — это параметр, который рано или поздно
 * не передадут; поэтому решение принято один раз и на все запросы.
 *
 * Органики служебный трафик почти не касается (headless ходит по прямым
 * адресам), и на цифре поиска фильтр не сказывается. Он всё равно стоит везде:
 * одно правило на все запросы дешевле, чем помнить, где оно нужно.
 */
final class MetrikaClient
{
    private const ENDPOINT = 'https://api-metrika.yandex.net/stat/v1/data';

    private const ENDPOINT_BYTIME = 'https://api-metrika.yandex.net/stat/v1/data/bytime';

    private const TIMEOUT = 30;

    /** Идентификатор нашего headless-браузера в справочнике Метрики. */
    private const HEADLESS_BROWSER = '204';

    /** Служебный трафик убрать (обычный режим). */
    public const WITHOUT_HEADLESS = 'without';

    /** Только служебный трафик — режим прибора, который его и замеряет. */
    public const ONLY_HEADLESS = 'only';

    private string $counter;

    private string $token;

    public function __construct(?string $counter = null, ?string $token = null)
    {
        $this->counter = trim((string) ($counter ?? config('services.metrika.counter')));
        $this->token = trim((string) ($token ?? config('services.metrika.token')));
    }

    public function configured(): bool
    {
        return $this->counter !== '' && $this->token !== '';
    }

    /** Сводный отчёт: суммы и разрезы по измерениям. */
    public function visits(array $params, string $headless = self::WITHOUT_HEADLESS): array
    {
        return $this->request(self::ENDPOINT, $params, $headless);
    }

    /** Ряд по времени: те же метрики, но разложенные по дням или неделям. */
    public function byTime(array $params, string $headless = self::WITHOUT_HEADLESS): array
    {
        return $this->request(self::ENDPOINT_BYTIME, $params, $headless);
    }

    private function request(string $url, array $params, string $headless): array
    {
        if (! $this->configured()) {
            throw new AnalyticsUnavailable('Метрика не настроена: нет счётчика или токена');
        }

        $params['ids'] = $this->counter;
        $params['filters'] = $this->withHeadlessFilter($params['filters'] ?? null, $headless);
        $params['accuracy'] ??= 'full';

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['Authorization' => 'OAuth '.$this->token])
                ->get($url, $params);
        } catch (\Throwable $e) {
            throw new AnalyticsUnavailable('Метрика недоступна: '.mb_substr($e->getMessage(), 0, 200));
        }

        if (! $response->successful()) {
            throw new AnalyticsUnavailable('Метрика ответила '.$response->status().': '.mb_substr((string) $response->body(), 0, 200));
        }

        return (array) $response->json();
    }

    private function withHeadlessFilter(?string $filters, string $headless): string
    {
        $own = $headless === self::ONLY_HEADLESS
            ? "ym:s:browser=='".self::HEADLESS_BROWSER."'"
            : "ym:s:browser!='".self::HEADLESS_BROWSER."'";

        $filters = trim((string) $filters);

        return $filters === '' ? $own : '('.$filters.') AND '.$own;
    }
}
