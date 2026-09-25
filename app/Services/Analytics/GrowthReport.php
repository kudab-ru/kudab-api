<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Event;
use App\Models\TelegramChatBroadcastItem;
use App\Repositories\EventRepository;
use App\Support\SourceOverview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Страница «Аналитика: рост» — всё, что нужно ответить на один вопрос:
 * мы выросли или это шум.
 *
 * Каждая секция собирается отдельно и в своём try/catch: Метрика и Вебмастер
 * падают независимо, и упавший Вебмастер не имеет права утащить с собой цифры
 * из нашей собственной базы. Что не собралось — приходит null и строкой в
 * meta.errors; подставлять нули нельзя, ноль неотличим от настоящего нуля и
 * читается как обвал.
 */
final class GrowthReport
{
    private const PERIOD_DAYS = 30;

    /** Двенадцать недель = ровно три четырёхнедельных блока для сравнения месяцев. */
    private const WEEKS = 12;

    private const BLOCK_WEEKS = 4;

    /**
     * Сколько визитов в месяц нужно прибавить, чтобы это вообще было видно.
     * Считано в docs/DIRECTION-2026-09.md: соседние недели скачут на −40/+43%,
     * и меньшая прибавка тонет в этом разбросе.
     */
    private const MIN_DETECTABLE_PER_MONTH = 100;

    private const ORGANIC = "ym:s:lastsignTrafficSource=='organic'";

    /**
     * В счётчике 26 целей, но живых из них четыре — остальные дубли и
     * заброшенные. Список держим здесь, а не в настройках: это не конфигурация,
     * а результат разбора, и менять его должен тот, кто разбор повторил.
     */
    private const GOALS = [
        574655999 => 'Просмотр события',
        574307808 => 'Минимальная заинтересованность',
        574656169 => 'Клик по билету',
        625513999 => 'Открыл второе событие',
    ];

    /**
     * Цели, которыми меряются уходы в телеграм.
     *
     * Своя цель «Переход в Telegram» (604001234) даёт ноль и будет давать:
     * из пяти входов в канал размечен один, а внутренний /telegram отвечает
     * редиректом, на котором хит не уходит. Поэтому считаем автоцелью
     * мессенджера — она ловит прямые t.me-ссылки — и отдельной целью бота.
     */
    private const TG_OUT_MESSENGER = 512237598;

    private const TG_OUT_BOT = 625515760;

    /** Карточка = группа сеансов; у одиночного события группы нет, ключ строим от id. */
    private const CARD = "COALESCE(events.event_group_id::text, 'e' || events.id)";

    /**
     * У каждого шестого будущего события время известно только датой, и без
     * запасного start_date сравнение даёт NULL — событие молча выпадает из
     * выборки, а число карточек занижается.
     */
    private const WHEN = 'COALESCE(events.start_time, events.start_date::timestamptz)';

    /** @var list<array{section:string,message:string}> */
    private array $errors = [];

    public function __construct(
        private readonly MetrikaClient $metrika,
        private readonly WebmasterClient $webmaster,
        private readonly SourceOverview $sources,
    ) {}

    public function build(): array
    {
        $this->errors = [];
        $now = CarbonImmutable::now();
        [$from, $to] = [$now->subDays(self::PERIOD_DAYS - 1)->toDateString(), $now->toDateString()];
        [$prevFrom, $prevTo] = [
            $now->subDays(self::PERIOD_DAYS * 2 - 1)->toDateString(),
            $now->subDays(self::PERIOD_DAYS)->toDateString(),
        ];

        $weekly = $this->guard('search', fn () => $this->weeklyOrganic(), null);
        $weeks = $weekly['weeks'] ?? [];
        $totals = $this->guard('search', fn () => $this->organicTotals($from, $to, $prevFrom, $prevTo), null);
        $paths = $this->guard('landing', fn () => $this->landingPaths($from, $to), null);
        $engines = $this->guard('sources', fn () => $this->engineTotals($from, $to), null);
        $allVisits = $this->guard('sources', fn () => $this->totalVisits($from, $to), null);
        $headless = $this->guard('sources', fn () => $this->headless($from, $to), null);
        $index = $this->guard('index', fn () => $this->index(), null);
        $queries = $this->guard('blind', fn () => $this->webmaster->searchQueriesSummary($from, $to), null);
        $goals = $this->guard('goals', fn () => $this->goals($from, $to), null);
        $cards = $this->guard('chain', fn () => $this->cards(), null);
        $telegram = $this->guard('telegram', fn () => $this->telegram($from, $to), null);

        return [
            'data' => [
                'generated_at' => $now->toIso8601String(),
                'period_days' => self::PERIOD_DAYS,
                'verdict' => $this->verdict($weeks),
                'search' => $this->search($weeks, $totals),
                'chain' => $this->chain($cards, $index, $paths),
                'sources' => $this->sourcesSection($allVisits, $engines, $weekly, $headless),
                'landing' => $paths === null ? null : $this->landing($paths),
                'card_sources' => $this->guard('card_sources', fn () => $this->cardSources($paths), null),
                'goals' => $goals,
                'telegram' => $telegram,
                'blind' => $this->blind($engines, $queries),
            ],
            'meta' => [
                'cached_until' => $now->addHours(6)->toIso8601String(),
                'stale' => false,
                'errors' => array_values($this->errors),
            ],
        ];
    }

    /** @template T */
    private function guard(string $section, \Closure $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $message = $e instanceof AnalyticsUnavailable
                ? $e->getMessage()
                : 'Не удалось собрать: '.mb_substr($e->getMessage(), 0, 200);

            // Одна и та же беда роняет по нескольку кусков секции; в meta
            // должна попасть одна строка, а не три одинаковых.
            $key = $section.'|'.$message;
            $this->errors[$key] ??= ['section' => $section, 'message' => $message];

            return $fallback;
        }
    }

    // ---------------------------------------------------------------- Метрика

    /**
     * Недельный ряд органики и её же разбивка по поисковикам — одним запросом.
     *
     * ТОЛЬКО ПОЛНЫЕ НЕДЕЛИ, Пн–Вс. Текущая неделя обрывается на сегодняшнем дне
     * и рядом с закрытыми выглядит провалом, поэтому её не отдаём вовсе.
     * Последнюю закрытую помечаем provisional: Метрика доуточняет её ещё
     * несколько дней, обычно на 2–9% вверх.
     */
    private function weeklyOrganic(): array
    {
        $monday = CarbonImmutable::now()->startOfWeek();
        $res = $this->metrika->byTime([
            'metrics' => 'ym:s:visits',
            'dimensions' => 'ym:s:lastsignSearchEngineRoot',
            'filters' => self::ORGANIC,
            'date1' => $monday->subWeeks(self::WEEKS)->toDateString(),
            'date2' => 'today',
            'group' => 'week',
            'limit' => 50,
        ]);

        $intervals = (array) ($res['time_intervals'] ?? []);
        $keep = [];
        foreach ($intervals as $i => $pair) {
            if ((string) ($pair[1] ?? '') < $monday->toDateString()) {
                $keep[$i] = [(string) $pair[0], (string) $pair[1]];
            }
        }

        $series = static function (array $values) use ($keep): array {
            $out = [];
            foreach (array_keys($keep) as $i) {
                $out[] = (int) round((float) ($values[$i] ?? 0));
            }

            return $out;
        };

        $totals = $series((array) (($res['totals'][0] ?? []) ?: []));
        $last = count($totals) - 1;

        $weeks = [];
        foreach (array_values($keep) as $n => [$wFrom, $wTo]) {
            $weeks[] = [
                'from' => $wFrom,
                'to' => $wTo,
                'visits' => $totals[$n] ?? 0,
                'provisional' => $n === $last,
            ];
        }

        $byEngine = [];
        foreach ((array) ($res['data'] ?? []) as $row) {
            $id = (string) ($row['dimensions'][0]['id'] ?? '');
            if ($id !== '') {
                $byEngine[$id] = $series((array) ($row['metrics'][0] ?? []));
            }
        }

        return ['weeks' => $weeks, 'by_engine' => $byEngine];
    }

    private function organicTotals(string $from, string $to, string $prevFrom, string $prevTo): array
    {
        $cur = $this->metrika->visits([
            'metrics' => 'ym:s:visits,ym:s:users',
            'filters' => self::ORGANIC,
            'date1' => $from,
            'date2' => $to,
        ]);
        $prev = $this->metrika->visits([
            'metrics' => 'ym:s:visits,ym:s:users',
            'filters' => self::ORGANIC,
            'date1' => $prevFrom,
            'date2' => $prevTo,
        ]);

        $visits = (int) round((float) ($cur['totals'][0] ?? 0));
        $prevVisits = (int) round((float) ($prev['totals'][0] ?? 0));

        return [
            'visits' => $visits,
            'users' => (int) round((float) ($cur['totals'][1] ?? 0)),
            'prev_visits' => $prevVisits,
            'delta_pct' => $prevVisits > 0 ? (int) round(($visits - $prevVisits) / $prevVisits * 100) : null,
        ];
    }

    /** @return list<array{path:string,visits:int}> */
    private function landingPaths(string $from, string $to): array
    {
        $res = $this->metrika->visits([
            'metrics' => 'ym:s:visits',
            'dimensions' => 'ym:s:startURLPath',
            'filters' => self::ORGANIC,
            'date1' => $from,
            'date2' => $to,
            'limit' => 1000,
        ]);

        $merged = [];
        foreach ((array) ($res['data'] ?? []) as $row) {
            $path = (string) ($row['dimensions'][0]['name'] ?? '');
            if ($path === '') {
                continue;
            }

            // Хвост запроса отрезаем: /events/12 и /events/12?src=past — один
            // адрес, а несклеенными они и страницу считают дважды, и медиану
            // «визитов на страницу» тянут вниз.
            $path = strtok($path, '?');
            $merged[$path] = ($merged[$path] ?? 0) + (int) round((float) ($row['metrics'][0] ?? 0));
        }

        arsort($merged);

        $out = [];
        foreach ($merged as $path => $visits) {
            $out[] = ['path' => (string) $path, 'visits' => $visits];
        }

        return $out;
    }

    private function engineTotals(string $from, string $to): array
    {
        $res = $this->metrika->visits([
            'metrics' => 'ym:s:visits',
            'dimensions' => 'ym:s:lastsignSearchEngineRoot',
            'filters' => self::ORGANIC,
            'date1' => $from,
            'date2' => $to,
            'limit' => 50,
        ]);

        $out = [];
        foreach ((array) ($res['data'] ?? []) as $row) {
            $id = (string) ($row['dimensions'][0]['id'] ?? '');
            if ($id !== '') {
                $out[$id] = (int) round((float) ($row['metrics'][0] ?? 0));
            }
        }

        return $out;
    }

    private function totalVisits(string $from, string $to): int
    {
        $res = $this->metrika->visits(['metrics' => 'ym:s:visits', 'date1' => $from, 'date2' => $to]);

        return (int) round((float) ($res['totals'][0] ?? 0));
    }

    /**
     * Сколько трафика дал наш же headless-браузер.
     *
     * Это прибор, а не статистика: пока число маленькое — агенты блокируют
     * mc.yandex.ru, как договаривались. День-залп в этом ряду означает, что
     * кто-то забыл, и цифры того дня во всех остальных разрезах уже не наши.
     */
    private function headless(string $from, string $to): array
    {
        $res = $this->metrika->byTime([
            'metrics' => 'ym:s:visits',
            'date1' => $from,
            'date2' => $to,
            'group' => 'day',
        ], MetrikaClient::ONLY_HEADLESS);

        $days = array_map(static fn ($pair) => (string) ($pair[0] ?? ''), (array) ($res['time_intervals'] ?? []));
        $values = (array) ($res['data'][0]['metrics'][0] ?? []);

        $total = 0;
        $spikeDate = null;
        $spikeVisits = 0;
        foreach ($days as $i => $day) {
            $v = (int) round((float) ($values[$i] ?? 0));
            $total += $v;
            if ($v > $spikeVisits) {
                $spikeVisits = $v;
                $spikeDate = $day;
            }
        }

        return ['visits' => $total, 'last_spike_date' => $spikeDate, 'last_spike_visits' => $spikeVisits];
    }

    private function goals(string $from, string $to): array
    {
        $ids = array_keys(self::GOALS);
        $metrics = implode(',', array_map(static fn ($id) => 'ym:s:goal'.$id.'visits', $ids));

        // Тот же фильтр, что у всей страницы. Без него цели считали ВЕСЬ
        // трафик: «874 просмотра события» стояло под «851 визитом из поиска»
        // и читалось как ошибка счёта.
        $res = $this->metrika->visits([
            'metrics' => $metrics,
            'filters' => self::ORGANIC,
            'date1' => $from,
            'date2' => $to,
        ]);

        $out = [];
        foreach ($ids as $n => $id) {
            $out[] = [
                'id' => $id,
                'label' => self::GOALS[$id],
                'visits' => (int) round((float) ($res['totals'][$n] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * Телеграм в обе стороны: сколько пришло по нашим меткам и сколько ушло
     * отсюда в канал и бота.
     *
     * Приток ловим по `utm_source=tg` — метку ставит наш же постинг
     * ([[EventCaptionBuilder]]), так что считаются именно переходы из канала,
     * а не любые заходы из мессенджера.
     */
    private function telegram(string $from, string $to): array
    {
        $in = $this->metrika->visits([
            'metrics' => 'ym:s:visits,ym:s:users',
            'filters' => "ym:s:UTMSource=='tg'",
            'date1' => $from,
            'date2' => $to,
        ]);

        $out = $this->metrika->visits([
            'metrics' => 'ym:s:goal'.self::TG_OUT_MESSENGER.'visits,ym:s:goal'.self::TG_OUT_BOT.'visits',
            'date1' => $from,
            'date2' => $to,
        ]);

        $since = CarbonImmutable::parse($from);
        // СТАТУС «posted», а не «sent»: такого статуса у записи нет вовсе
        // (см. константы TelegramChatBroadcastItem), поэтому счётчик молча
        // отдавал нули и весь блок «телеграм в обе стороны» на главной
        // выглядел пустым — как будто по постам не переходят.
        $posts = TelegramChatBroadcastItem::query()
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->where('posted_at', '>=', $since)
            ->selectRaw('count(*) as sent')
            ->selectRaw('count(*) filter (where clicks is not null) as measured')
            ->selectRaw('count(*) filter (where coalesce(clicks, 0) > 0) as with_clicks')
            ->selectRaw('coalesce(sum(clicks), 0) as clicks')
            ->first();

        return [
            'in_visits' => (int) round((float) ($in['totals'][0] ?? 0)),
            'in_users' => (int) round((float) ($in['totals'][1] ?? 0)),
            'out_messenger' => (int) round((float) ($out['totals'][0] ?? 0)),
            'out_bot' => (int) round((float) ($out['totals'][1] ?? 0)),
            'posts_sent' => (int) ($posts->sent ?? 0),
            'posts_measured' => (int) ($posts->measured ?? 0),
            'posts_with_clicks' => (int) ($posts->with_clicks ?? 0),
            'post_clicks' => (int) ($posts->clicks ?? 0),
        ];
    }

    // -------------------------------------------------------------- Вебмастер

    private function index(): array
    {
        $history = $this->webmaster->indexHistory();
        if ($history === []) {
            throw new AnalyticsUnavailable('Вебмастер не отдал историю индекса');
        }

        $last = $history[count($history) - 1];
        $cut = CarbonImmutable::parse($last['date'])->subDays(self::PERIOD_DAYS)->toDateString();

        $prev = null;
        foreach ($history as $point) {
            if ($point['date'] <= $cut) {
                $prev = $point;
            }
        }
        $prev ??= $history[0];

        return [
            'pages' => $last['value'],
            'prev' => $prev['value'],
            'delta' => $last['value'] - $prev['value'],
            'date' => $last['date'],
            'prev_date' => $prev['date'],
        ];
    }

    // ------------------------------------------------------------------ Сборка

    private function search(array $weeks, ?array $totals): ?array
    {
        if ($totals === null && $weeks === []) {
            return null;
        }

        return [
            'visits' => $totals['visits'] ?? null,
            'users' => $totals['users'] ?? null,
            'prev_visits' => $totals['prev_visits'] ?? null,
            'delta_pct' => $totals['delta_pct'] ?? null,
            'weeks' => $weeks,
            'noise_pct' => $this->noisePct($weeks),
            'min_detectable_per_month' => self::MIN_DETECTABLE_PER_MONTH,
        ];
    }

    /**
     * Разброс соседних недель: насколько сильно неделя отходит от среднего
     * своего четырёхнедельного блока. Это и есть цена деления прибора — рост
     * меньше этого числа отличить от случайности нечем.
     */
    private function noisePct(array $weeks): ?int
    {
        // Только два последних блока. На старте трафика неделя в 23 визита
        // отклонялась от своего месяца на 53% просто потому, что числа
        // маленькие, и коридор раздувался до ±55% — при таком пороге на
        // сегодняшних объёмах не заметна уже никакая просадка.
        $blocks = array_slice($this->blocks($weeks), -2);
        if ($blocks === []) {
            return null;
        }

        $max = 0.0;
        foreach ($blocks as $block) {
            $values = array_column($block, 'visits');
            $mean = array_sum($values) / count($values);
            if ($mean <= 0) {
                continue;
            }
            foreach ($values as $v) {
                $max = max($max, abs($v - $mean) / $mean);
            }
        }

        return (int) (ceil($max * 100 / 5) * 5);
    }

    /**
     * Недели четвёрками, старые → новые. Неполный остаток отбрасываем С НАЧАЛА:
     * сравнивают всегда последний месяц с предыдущим, и он обязан быть целым.
     *
     * @return list<list<array{from:string,to:string,visits:int}>>
     */
    private function blocks(array $weeks): array
    {
        $whole = intdiv(count($weeks), self::BLOCK_WEEKS) * self::BLOCK_WEEKS;
        if ($whole === 0) {
            return [];
        }

        return array_chunk(array_slice($weeks, count($weeks) - $whole), self::BLOCK_WEEKS);
    }

    private function verdict(array $weeks): array
    {
        $blocks = $this->blocks($weeks);
        $sums = [];
        foreach ($blocks as $block) {
            $sums[] = [
                'from' => $block[0]['from'],
                'to' => $block[count($block) - 1]['to'],
                'visits' => array_sum(array_column($block, 'visits')),
            ];
        }

        if (count($sums) < 2 || $sums[count($sums) - 2]['visits'] <= 0) {
            return [
                'state' => 'unknown',
                'title' => 'Не знаем',
                'note' => 'Недельного ряда не хватает, чтобы сравнить месяц с месяцем',
                'blocks' => $sums,
            ];
        }

        $last = $sums[count($sums) - 1]['visits'];
        $prev = $sums[count($sums) - 2]['visits'];
        $ratio = $last / $prev;

        // Порог берём от измеренного шума, а не с потолка. Блок — это сумма
        // четырёх недель, и недельный разброс в ней уже наполовину усреднён;
        // отсюда noise/2. Нижняя граница в 10% — чтобы на тихом ряду страница
        // не объявляла ростом случайные полтора визита.
        $threshold = max(10, (int) round((float) ($this->noisePct($weeks) ?? 40) / 2)) / 100;
        $change = $ratio - 1;

        if ($change > $threshold) {
            return [
                'state' => 'growing',
                'title' => 'Росли',
                'note' => 'Месяц к месяцу трафик '.$this->howMuch($ratio, 'вырос'),
                'blocks' => $sums,
            ];
        }

        if ($change < -$threshold) {
            return [
                'state' => 'falling',
                'title' => 'Падали',
                'note' => 'Месяц к месяцу трафик '.$this->howMuch($ratio > 0 ? 1 / $ratio : 0.0, 'упал'),
                'blocks' => $sums,
            ];
        }

        return [
            'state' => 'flat',
            'title' => 'Ровно',
            'note' => 'Месяц к месяцу разница '.(int) round(abs($change) * 100).'% — это в пределах шума',
            'blocks' => $sums,
        ];
    }

    /** «вырос в 1,65 раза» для заметной разницы, «вырос на 14%» для небольшой. */
    private function howMuch(float $times, string $verb): string
    {
        if ($times >= 1.2) {
            $text = rtrim(rtrim(number_format($times, 2, ',', ''), '0'), ',');

            return $verb.' в '.$text.' раза';
        }

        return $verb.' на '.(int) round(abs($times - 1) * 100).'%';
    }

    private function chain(?array $cards, ?array $index, ?array $paths): ?array
    {
        if ($cards === null && $index === null && $paths === null) {
            return null;
        }

        return [
            'cards_ahead' => $cards['ahead'] ?? null,
            'cards_horizon' => $cards['horizon'] ?? null,
            'cards_expiring_7d' => $cards['expiring_7d'] ?? null,
            'cards_in_feed' => $cards['in_feed'] ?? null,
            'cards_added_30d' => $cards['added_30d'] ?? null,
            'index_pages' => $index['pages'] ?? null,
            'index_prev' => $index['prev'] ?? null,
            'index_delta' => $index['delta'] ?? null,
            'index_date' => $index['date'] ?? null,
            'index_prev_date' => $index['prev_date'] ?? null,
            'landing_pages' => $paths === null ? null : count($paths),
        ];
    }

    private function sourcesSection(?int $total, ?array $engines, ?array $weekly, ?array $headless): ?array
    {
        if ($total === null && $engines === null) {
            return null;
        }

        $labels = ['yandex' => 'Поиск Яндекса', 'google' => 'Поиск Google'];
        $rows = [];
        $named = 0;
        foreach ($labels as $key => $label) {
            $visits = (int) ($engines[$key] ?? 0);
            $named += $visits;
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'visits' => $visits,
                'weeks' => $weekly['by_engine'][$key] ?? null,
            ];
        }

        usort($rows, static fn ($a, $b) => $b['visits'] <=> $a['visits']);

        // «Остальное» — это остаток от всего трафика: прямые заходы, переходы
        // из телеграма, с других сайтов. Разложить его по неделям нечем одним
        // запросом, и страница показывает его числом без ряда.
        $rows[] = [
            'key' => 'other',
            'label' => 'Остальное',
            'visits' => max(0, (int) $total - $named),
            'weeks' => null,
        ];

        return [
            'total' => $total,
            'rows' => $rows,
            'filtered_headless' => $headless,
        ];
    }

    private function landing(array $paths): array
    {
        $groups = [
            'events' => ['Карточки событий', 0],
            'afisha' => ['Афиша города', 0],
            'venues' => ['Площадки', 0],
            'home' => ['Главная', 0],
            'other' => ['Остальное', 0],
        ];

        $visits = [];
        foreach ($paths as $row) {
            $groups[$this->landingGroup($row['path'])][1] += $row['visits'];
            $visits[] = $row['visits'];
        }

        $rows = [];
        foreach ($groups as $key => [$label, $sum]) {
            $rows[] = ['key' => $key, 'label' => $label, 'visits' => $sum];
        }

        $buckets = ['1' => 0, '2-4' => 0, '5-9' => 0, '10+' => 0];
        foreach ($visits as $v) {
            $buckets[match (true) {
                $v <= 1 => '1',
                $v <= 4 => '2-4',
                $v <= 9 => '5-9',
                default => '10+',
            }]++;
        }

        $distribution = [];
        foreach ($buckets as $bucket => $pages) {
            // Строкой, а не числом: «1» и «2-4» стоят в одном столбце, и
            // PHP успевает превратить ключ «1» в целое до того, как массив
            // станет json — фронт получал разнотипные подписи.
            $distribution[] = ['bucket' => (string) $bucket, 'pages' => $pages];
        }

        $sum = array_sum($visits);
        $top10 = array_sum(array_slice($visits, 0, 10));

        return [
            'rows' => $rows,
            'distribution' => $distribution,
            'median' => $this->median($visits),
            'top10_share_pct' => $sum > 0 ? round($top10 / $sum * 100, 1) : null,
        ];
    }

    private function landingGroup(string $path): string
    {
        return match (true) {
            $path === '' || $path === '/' => 'home',
            str_starts_with($path, '/events/') => 'events',
            str_starts_with($path, '/afisha') => 'afisha',
            str_starts_with($path, '/venues') => 'venues',
            default => 'other',
        };
    }

    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }

    private function blind(?array $engines, ?array $queries): array
    {
        return [
            'google_visits' => $engines === null ? null : (int) ($engines['google'] ?? 0),
            // Google Search Console не подключена: ручки нет, токена нет,
            // числа взять неоткуда. Половина поиска для нас — тёмная комната,
            // и страница обязана говорить об этом прямо.
            'gsc_connected' => false,
            'webmaster_shows' => $queries['shows'] ?? null,
            'webmaster_clicks' => $queries['clicks'] ?? null,
            'webmaster_lag_days' => $queries['lag_days'] ?? null,
        ];
    }

    // ----------------------------------------------------------------- База

    /**
     * Живые карточки.
     *
     * СТРОГО БУДУЩИЕ, без окна прошлого. Лента показывает и вчерашнее
     * (EventRepository::PAST_LOOKBACK_DAYS), потому что человеку полезно
     * увидеть, что было рядом; но в аналитике роста «живая карточка» — это та,
     * на которую ещё можно прийти. Второе число, cards_in_feed, считается по
     * ленточному правилу и нужно только подписью: столько видит читатель.
     */
    private function cards(): array
    {
        $rows = DB::query()
            ->fromSub($this->futureEvents()->selectRaw(self::CARD.' as card, '.self::WHEN.' as ts'), 't')
            ->selectRaw('card, MIN(ts) as first_ts, MAX(ts) as last_ts')
            ->groupBy('card')
            ->get();

        $now = CarbonImmutable::now();
        $horizon = [0, 0, 0, 0, 0];
        $expiring = 0;

        foreach ($rows as $row) {
            $weeksAhead = (int) floor($now->diffInDays(CarbonImmutable::parse($row->first_ts), false) / 7);
            $horizon[min(4, max(0, $weeksAhead))]++;

            if (CarbonImmutable::parse($row->last_ts) <= $now->addDays(7)) {
                $expiring++;
            }
        }

        return [
            'ahead' => $rows->count(),
            'horizon' => $horizon,
            'expiring_7d' => $expiring,
            'in_feed' => $this->cardsInFeed(),
            'added_30d' => $this->cardsAdded30d(),
        ];
    }

    /**
     * Сколько карточек из нынешнего запаса заведено за последний месяц.
     *
     * Считаем именно пересечение «впереди И заведено недавно», а не весь
     * приток: за 30 дней заводится под полторы тысячи карточек, но почти все
     * они к сегодняшнему дню уже состоялись, и рядом со строкой «за неделю
     * уйдёт 133» такое число обещало бы рост запаса, которого нет.
     */
    private function cardsAdded30d(): int
    {
        return (int) $this->futureEvents()
            ->where('events.created_at', '>=', CarbonImmutable::now()->subDays(30))
            ->distinct()
            ->count(DB::raw(self::CARD));
    }

    private function cardsInFeed(): int
    {
        $nowMsk = now('Europe/Moscow');
        $cutoff = $nowMsk->copy()->subDays(EventRepository::PAST_LOOKBACK_DAYS);

        return (int) $this->feedBase()
            ->where(function ($w) use ($cutoff) {
                $w->where('events.start_time', '>=', $cutoff)
                    ->orWhere(function ($x) use ($cutoff) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->where('events.start_date', '>=', $cutoff->toDateString());
                    });
            })
            ->distinct()
            ->count(DB::raw(self::CARD));
    }

    private function futureEvents(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->feedBase()->whereRaw(self::WHEN.' > now()');
    }

    private function feedBase(): \Illuminate\Database\Eloquent\Builder
    {
        return Event::query()
            ->join('cities as ct', 'ct.id', '=', 'events.city_id')
            ->where('ct.status', 'active')
            ->where('events.status', 'active')
            ->whereNull('events.deleted_at')
            ->webNotBlacklisted();
    }

    /**
     * Какой источник событий кормит не карточки, а живой поисковый трафик.
     *
     * Визит приземлился на /events/{id} — значит, его заработал тот, кто это
     * событие принёс. У события бывает несколько источников (пост в ВК и
     * строка на Я.Афише про один и тот же концерт), и тогда визит отдаём
     * ПЕРВОМУ по дате привязки: разделить его честно нечем, а раздать обоим
     * значит насчитать трафика вдвое больше, чем был.
     *
     * events_matched говорит, сколько адресов вообще удалось привязать. Если
     * оно заметно меньше числа адресов — часть событий уже удалена или пришла
     * без источника, и строкам верить можно только на эту долю.
     */
    private function cardSources(?array $paths): ?array
    {
        if ($paths === null) {
            return null;
        }

        $visitsByEvent = [];
        foreach ($paths as $row) {
            if (preg_match('~^/events/(\d+)~', $row['path'], $m)) {
                $id = (int) $m[1];
                $visitsByEvent[$id] = ($visitsByEvent[$id] ?? 0) + $row['visits'];
            }
        }

        $map = $this->sources->sourceByLink();
        $rows = [];
        $row = static function (string $key, string $label) use (&$rows): void {
            $rows[$key] ??= ['key' => $key, 'label' => $label, 'cards_ahead' => 0, 'visits_30d' => 0, 'events_matched' => 0];
        };

        if ($visitsByEvent !== []) {
            $linkByEvent = DB::select(
                'SELECT DISTINCT ON (es.event_id) es.event_id, es.social_link_id
                 FROM event_sources es
                 WHERE es.event_id = ANY(?)
                 ORDER BY es.event_id, es.created_at ASC NULLS LAST, es.id ASC',
                ['{'.implode(',', array_keys($visitsByEvent)).'}'],
            );

            foreach ($linkByEvent as $link) {
                $source = $map[(int) $link->social_link_id] ?? null;
                if ($source === null) {
                    continue;
                }
                $row($source['key'], $source['label']);
                $rows[$source['key']]['visits_30d'] += $visitsByEvent[(int) $link->event_id] ?? 0;
                $rows[$source['key']]['events_matched']++;
            }
        }

        $cardRows = DB::query()
            ->fromSub(
                $this->futureEvents()
                    ->join('event_sources as es', 'es.event_id', '=', 'events.id')
                    ->selectRaw(self::CARD.' as card, es.social_link_id, es.created_at as attached_at, es.id as src_id'),
                't'
            )
            ->selectRaw('DISTINCT ON (t.card) t.card, t.social_link_id')
            ->orderByRaw('t.card, t.attached_at ASC NULLS LAST, t.src_id ASC')
            ->get();

        foreach ($cardRows as $card) {
            $source = $map[(int) $card->social_link_id] ?? null;
            if ($source === null) {
                continue;
            }
            $row($source['key'], $source['label']);
            $rows[$source['key']]['cards_ahead']++;
        }

        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => [$b['visits_30d'], $b['cards_ahead']] <=> [$a['visits_30d'], $a['cards_ahead']]);

        return $rows;
    }
}
