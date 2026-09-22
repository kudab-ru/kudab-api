<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Http;

/**
 * Яндекс.Вебмастер: число страниц в индексе и сводка показов с кликами.
 *
 * ЧТО ОН НЕ ЗНАЕТ. Вебмастер видит только Яндекс, а тот даёт примерно половину
 * нашего поиска — вторая половина Google, и про неё здесь нет ничего. Числа
 * отсюда нельзя складывать с числами Метрики и нельзя выдавать за «весь
 * поиск»; на странице они живут в отдельном разделе «чего мы не знаем».
 *
 * ЛАГ ДВА ДНЯ. За вчера и сегодня ряды приходят пустыми или нулевыми, и это не
 * падение трафика — это ещё не посчитанные данные. Поэтому берём последнюю
 * НЕПУСТУЮ точку и отдаём её дату вместе со значением: без даты нулевой хвост
 * читается как обвал.
 */
final class WebmasterClient
{
    private const BASE = 'https://api.webmaster.yandex.net/v4';

    private const TIMEOUT = 30;

    private string $user;

    private string $host;

    private string $token;

    public function __construct(?string $user = null, ?string $host = null, ?string $token = null)
    {
        $this->user = trim((string) ($user ?? config('services.webmaster.user_id')));
        $this->host = trim((string) ($host ?? config('services.webmaster.host_id')));
        $this->token = trim((string) ($token ?? config('services.webmaster.token')));
    }

    public function configured(): bool
    {
        return $this->user !== '' && $this->host !== '' && $this->token !== '';
    }

    /**
     * Страницы в поиске, по дням.
     *
     * @return list<array{date:string,value:int}> старые → новые, пустые точки выброшены
     */
    public function indexHistory(): array
    {
        $raw = (array) ($this->get('search-urls/in-search/history/')['history'] ?? []);

        $out = [];
        foreach ($raw as $point) {
            $date = substr((string) ($point['date'] ?? ''), 0, 10);
            $value = (int) round((float) ($point['value'] ?? 0));

            if ($date === '' || $value <= 0) {
                continue;
            }

            $out[] = ['date' => $date, 'value' => $value];
        }

        usort($out, static fn ($a, $b) => $a['date'] <=> $b['date']);

        return $out;
    }

    /**
     * Показы и клики в Яндексе за период.
     *
     * @return array{shows:int,clicks:int,last_date:?string,lag_days:?int}
     */
    public function searchQueriesSummary(string $from, string $to): array
    {
        $data = $this->get('search-queries/all/history/', [
            'query_indicator' => ['TOTAL_SHOWS', 'TOTAL_CLICKS'],
            'date_from' => $from,
            'date_to' => $to,
        ]);

        $indicators = (array) ($data['indicators'] ?? []);
        $shows = $this->sum($indicators['TOTAL_SHOWS'] ?? []);
        $clicks = $this->sum($indicators['TOTAL_CLICKS'] ?? []);
        $lastDate = $this->lastNonEmptyDate($indicators['TOTAL_SHOWS'] ?? []);

        return [
            'shows' => $shows,
            'clicks' => $clicks,
            'last_date' => $lastDate,
            'lag_days' => $lastDate === null ? null : (int) now()->startOfDay()->diffInDays($lastDate, true),
        ];
    }

    private function sum(mixed $points): int
    {
        $total = 0;
        foreach ((array) $points as $point) {
            $total += (int) round((float) ($point['value'] ?? 0));
        }

        return $total;
    }

    private function lastNonEmptyDate(mixed $points): ?string
    {
        $last = null;
        foreach ((array) $points as $point) {
            $date = substr((string) ($point['date'] ?? ''), 0, 10);
            if ($date !== '' && (float) ($point['value'] ?? 0) > 0 && ($last === null || $date > $last)) {
                $last = $date;
            }
        }

        return $last;
    }

    /**
     * Строку запроса собираем сами: показатели передаются ПОВТОРЯЮЩИМСЯ
     * ключом (query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS), а
     * обычная сборка из массива дала бы query_indicator[0]=… — ручка на это
     * отвечает двумя сотнями с пустыми рядами, и отчёт молча показывал нули
     * вместо показов.
     */
    private static function queryString(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            foreach ((array) $value as $one) {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $one);
            }
        }

        return $parts === [] ? '' : '?'.implode('&', $parts);
    }

    private function get(string $path, array $query = []): array
    {
        if (! $this->configured()) {
            throw new AnalyticsUnavailable('Вебмастер не настроен: нет токена или идентификаторов');
        }

        $url = self::BASE.'/user/'.$this->user.'/hosts/'.$this->host.'/'.$path;

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['Authorization' => 'OAuth '.$this->token])
                ->get($url.self::queryString($query));
        } catch (\Throwable $e) {
            throw new AnalyticsUnavailable('Вебмастер недоступен: '.mb_substr($e->getMessage(), 0, 200));
        }

        if (! $response->successful()) {
            throw new AnalyticsUnavailable('Вебмастер не ответил: '.$response->status());
        }

        return (array) $response->json();
    }
}
