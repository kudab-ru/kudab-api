<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Models\City;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Где сайт зовёт на страницу праздника: лента на главной, плашка на странице события из программы
 * и карточка в поиске. Праздник во всех трёх местах описан одинаково, см. summary.
 */
final class OccasionPromo
{
    private const TZ = 'Europe/Moscow';

    /** Страница собирается из нескольких запросов и сборки поста автоматом, а меняется медленно. */
    private const CACHE_SECONDS = 600;

    /** За сколько дней до праздника сайт начинает звать на его страницу. */
    private const SOON_DAYS = 14;

    public function __construct(private readonly OccasionPage $page) {}

    /** @param  array<string, mixed>  $theme */
    public function pageData(array $theme, City $city): array
    {
        return Cache::remember(
            self::pageKey($theme, $city),
            self::CACHE_SECONDS,
            fn () => $this->page->build($theme, $city, Carbon::now()),
        );
    }

    /**
     * Программу поправили руками — страница собирается заново, а не ждёт конца кэша.
     *
     * @param  array<string, mixed>  $theme
     */
    public static function forgetPage(City $city, array $theme): void
    {
        Cache::forget(self::pageKey($theme, $city));
    }

    /** @param  array<string, mixed>  $theme */
    private static function pageKey(array $theme, City $city): string
    {
        return 'occasion-page:v3:'.$city->slug.':'.$theme['page'];
    }

    /** Ближайший праздник для ленты на главной; null — он дальше двух недель или событий у него нет. */
    public function soon(City $city, Carbon $now): ?array
    {
        $first = $this->near($now)[0] ?? null;
        if ($first === null) {
            return null;
        }
        $summary = $this->summary($first, $city, $now);

        return $summary['total'] > 0 ? $summary : null;
    }

    /** Праздник, в программу которого входит событие, пока сайт на него зовёт; null — ни в один не входит. */
    public function forEvent(Event $event, Carbon $now): ?array
    {
        $city = City::query()->find($event->city_id);
        if ($city === null) {
            return null;
        }
        foreach ($this->near($now) as $o) {
            $theme = OccasionCalendar::byPage($o['page']);
            if ($theme !== null && $this->page->includes($theme, $city, (int) $event->id, $now)) {
                return $this->summary($o, $city, $now);
            }
        }

        return null;
    }

    /**
     * Праздник, названный в запросе поиска; null — запрос не про праздник. Название сильнее слова программы:
     * «новый год» есть в словах ёлок и концертов, но называет ночь. При равенстве берём ближайший.
     */
    public function match(string $query, City $city, Carbon $now): ?array
    {
        $text = self::norm($query);
        $best = null;
        $bestScore = 0;
        foreach ($this->page->calendar($now) as $o) {
            $theme = OccasionCalendar::byPage($o['page']);
            $score = $theme === null ? 0 : self::score($text, $theme);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$o, $score];
            }
        }

        return $best === null ? null : $this->summary($best, $city, $now);
    }

    /**
     * 2 — запрос называет праздник, 1 — в запросе слово, по которому собирается его программа или которое
     * указано для поиска (`search`), 0 — запрос не про этот праздник. Части названия мало: «концерт»
     * не про «Новогодние концерты».
     *
     * @param  array<string, mixed>  $theme
     */
    private static function score(string $text, array $theme): int
    {
        $title = self::norm((string) $theme['title']);
        if ($text === '' || $title === '') {
            return 0;
        }
        if (str_contains($text, $title)) {
            return 2;
        }
        foreach ([...(array) ($theme['words'] ?? []), ...(array) ($theme['search'] ?? [])] as $word) {
            $word = self::norm((string) $word);
            if ($word !== '' && preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($word, '/').'/u', $text)) {
                return 1;
            }
        }

        return 0;
    }

    /** Праздники, на которые сайт зовёт сейчас: за две недели до начала и до конца окна. */
    private function near(Carbon $now): array
    {
        $now = $now->copy()->setTimezone(self::TZ);

        return array_values(array_filter($this->page->calendar($now), function (array $o) use ($now) {
            $start = Carbon::parse($o['badge'], self::TZ);
            $end = Carbon::parse($o['until'], self::TZ)->endOfDay();

            return ! $now->lt($start->copy()->subDays(self::SOON_DAYS)) && ! $now->gt($end);
        }));
    }

    /**
     * Строка календаря и то, что видно на ленте и плашке: сколько событий, виды и три события программы.
     * Число событий берётся со страницы праздника, чтобы плашка и страница не расходились.
     *
     * @param  array<string, mixed>  $o  строка OccasionPage::calendar
     */
    private function summary(array $o, City $city, Carbon $now): array
    {
        $data = $this->pageData((array) OccasionCalendar::byPage($o['page']), $city);
        $start = Carbon::parse($o['badge'], self::TZ);

        return $o + [
            'days_left' => max(0, (int) $now->copy()->setTimezone(self::TZ)->startOfDay()->diffInDays($start, false)),
            'total' => $data['occasion']['total'],
            'lead' => $data['occasion']['lead'],
            'kinds' => array_slice(array_column($data['kinds'], 'label'), 0, 3),
            'program' => OccasionPage::program($data),
        ];
    }

    private static function norm(string $s): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s))));
    }
}
