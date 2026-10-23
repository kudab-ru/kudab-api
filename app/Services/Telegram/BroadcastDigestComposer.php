<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Occasions\OccasionCalendar;
use App\Support\CalmCaps;
use App\Support\CityCase;
use App\Support\EventStart;
use App\Support\Telegram\KindEmoji;
use App\Support\Telegram\VenueName;
use App\Support\WeekendWindow;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Собрать подборку недели: тему, состав и текст.
 *
 * КОГДА. Состав — за prepare_hours до слота (broadcast:prepare-digests),
 * подпись — перед самой отправкой. Почему не при постановке брони — см.
 * [[BroadcastDigestBooking]].
 *
 * ЧТО ПОКАЗЫВАЕТ. Три события поимённо, остальное числом. Это не приём
 * оформления, а решение о размере задачи: названное событие закрывается для
 * собственного поста, и называть десять — значит забрать у ленты десять постов.
 *
 * ТЕМА. Только из реестра лендингов — причины в шапке
 * config/broadcast_digest.php.
 */
final class BroadcastDigestComposer
{
    private const TZ = 'Europe/Moscow';

    /**
     * Сколько дней канал помнит показанное событие.
     *
     * Публичная, потому что по этому же окну обязан судить и отказ при ручной
     * замене позиции: пул предлагал событие, показанное десять дней назад, а
     * ручка отвечала «канал уже показывал» — кандидат висел в панели и не
     * ставился никогда. Два правила про одно и то же расходились молча.
     */
    public const SHOWN_WINDOW_DAYS = 7;

    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    /** @var array<int, string> */
    private const WEEKDAYS = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];

    /**
     * Ниже этого фразу заказывать бессмысленно: в 40 знаков помещается
     * назывное предложение, а не мысль. Если бюджет вышел меньше — значит
     * строк слишком много, и это чинится составом, а не длиной фразы.
     */
    private const HOOK_BUDGET_MIN = 40;

    /** Больше сеансов в строку не склеиваем: перечисление становится шумом. */
    private const MERGE_MAX = 3;

    /** Сколько места осталось на фразу в ПОСЛЕДНЕЙ собранной подписи. */
    private int $hookBudget = 0;

    /**
     * Состав в порядке последней собранной подписи. Он же пишется в связь «пост → события»,
     * чтобы обложки и строки в админке шли как в посте.
     *
     * @var list<int>
     */
    private array $postOrder = [];

    /** Событие, которое ищет `missReason`; null — отбор идёт как обычно. */
    private ?int $tracing = null;

    /** Правило, на котором искомое событие выпало из отбора. */
    private ?string $lostAt = null;

    /**
     * Лента нужна ради ОДНОГО числа — того же, что читатель увидит, нажав на
     * подвал. Своё считать нельзя: пул рубрики уже прошёл стоп-лист, жанровые
     * отсечки, вычет показанного и схлопывание повторов, и к числу на странице
     * отношения не имеет.
     */
    public function __construct(
        private readonly \App\Services\EventService $events,
        private readonly Scoring\EventBroadcastScorer $scorer,
    ) {}

    /**
     * Собрать подборку для канала на момент публикации.
     *
     * @return array{theme: array<string, string>, caption: string, event_ids: list<int>, total: int, venues: int}|null
     *                                                                                                                  null — ни одна тема не набрала состава; решать, что делать дальше, вызывающему
     */
    public function compose(
        TelegramChatBroadcast $broadcast,
        Carbon $publishAt,
        ?TelegramChatBroadcastItem $forItem = null,
        ?string $themeSlug = null,
    ): ?array {
        $cityId = $broadcast->chat?->city_id;
        if (! $cityId) {
            return null;
        }

        // Рубрики, выходившие недавно, уходят в конец очереди: канал с одной
        // и той же «неделей спектаклей» читается как заевшая пластинка. Это и
        // есть «ориентироваться по постам на неделе».
        $recent = $this->recentThemeSlugs($broadcast);

        $best = null;
        $bestRank = null;

        $gathered = [];

        $weekday = (int) $publishAt->copy()->setTimezone(self::TZ)->isoWeekday();

        foreach ((array) config('broadcast_digest.themes', []) as $theme) {
            // Рубрику назвали руками — остальные не считаем. Ограничение по
            // дню на выбранную не действует: человек решил.
            if ($themeSlug !== null && (string) ($theme['slug'] ?? '') !== $themeSlug) {
                continue;
            }

            // Рубрика может иметь смысл не в любой день. «На выходных» во
            // вторник — это приглашение подождать четыре дня; такое читают
            // один раз и отписываются.
            $days = (array) ($theme['only_weekdays'] ?? []);
            if ($themeSlug === null && $days !== [] && ! in_array($weekday, array_map('intval', $days), true)) {
                continue;
            }

            if ($themeSlug === null && ! DigestCadence::due((int) $broadcast->id, (array) $theme, $publishAt, $forItem?->id)) {
                continue;
            }

            // поводы и «Что смотрят» выходят по своему расписанию, ротация их не берёт
            if ($themeSlug === null && ($theme['auto'] ?? true) === false) {
                continue;
            }

            $picked = $this->pickForTheme($broadcast, (int) $cityId, (array) $theme, $publishAt, $forItem?->id);
            if ($picked !== null) {
                $gathered[(string) ($theme['slug'] ?? '')] = [$theme, $picked];
            }
        }

        foreach ($gathered as $slug => [$theme, $picked]) {
            // ЗАПАСНАЯ РУБРИКА ждёт своего часа. «Дешевле 500» выходит только
            // в неделю, где даром нечего: прямая просьба владельца —
            // бесплатное первым, платное запасным.
            $insteadOf = (string) ($theme['only_if_missing'] ?? '');
            if ($insteadOf !== '' && isset($gathered[$insteadOf])) {
                continue;
            }

            // Старшинство: сперва свежесть рубрики (канал с одной и той же
            // «неделей спектаклей» читается как заевшая пластинка), потом
            // число РАЗНЫХ площадок — пять концертов в одном баре это афиша
            // бара, а не тема недели.
            $rank = [
                // у рубрики раз в две недели подошёл срок: иначе ротация отложит её на неопределённое время
                empty($theme['every_days']) ? 1 : 0,
                array_search($slug, $recent, true) === false ? 0 : 1,
                -$picked['venues'],
            ];

            if ($bestRank === null || $rank < $bestRank) {
                $bestRank = $rank;
                $best = $picked;
            }
        }

        if ($best === null) {
            return null;
        }

        $caption = $this->buildCaption($broadcast, $best, $publishAt, $forItem);

        return [
            'theme' => $best['theme'],
            'theme_slug' => (string) $best['theme']['slug'],
            'caption' => $caption,
            // Сколько знаков осталось на фразу — читает парсер, когда её пишет.
            'hook_budget' => $this->hookBudget,
            'event_ids' => $this->postOrder,
            'total' => $best['total'],
            'venues' => $best['venues'],
        ] + $this->windowMeta((array) $best['theme'], $publishAt);
    }

    /**
     * Слаги рубрик, выходивших в этом канале последними.
     *
     * Смотрим на посты, а не на календарь: подборку могли снять, перенести
     * или выпустить руками, и «что выходило» знает только лента.
     *
     * @return list<string>
     */
    private function recentThemeSlugs(TelegramChatBroadcast $broadcast): array
    {
        $depth = (int) config('broadcast_digest.rotation_depth', 4);
        if ($depth <= 0) {
            return [];
        }

        return DB::table('telegram.chat_broadcast_items')
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->whereNotNull('digest_meta')
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_POSTED,
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
            ])
            ->orderByDesc('id')
            ->limit($depth)
            ->pluck('digest_meta')
            ->map(fn ($m) => (string) (json_decode((string) $m, true)['theme'] ?? ''))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Пересобрать подпись по УЖЕ ВЫБРАННОМУ составу.
     *
     * ЗАЧЕМ ОТДЕЛЬНО ОТ compose(). Между выбором состава и отправкой проходит
     * окно, в которое модель пишет текст, а человек может перетащить пост на
     * другой день. Полная пересборка в этот момент выбрала бы ДРУГУЮ тройку —
     * `rejectAlreadyShown` вычитает всё, что канал вот-вот покажет, а покажет
     * он ровно эти три, — и оплаченный текст достался бы не тем событиям.
     * Здесь состав берётся из связи «пост → события» и не переизбирается.
     *
     * Что всё-таки пересчитывается: факты событий (цена, время, площадка) и
     * счётчики пула. Они читаются заново, потому что подпись собирается под
     * МОМЕНТ отправки: пост 210 ушёл 15-го с шапкой «С 16 по 23 сентября»
     * именно потому, что текст собрали под один день, а отправили в другой.
     *
     * @return array{theme: array<string, string>, theme_slug: string, caption: string, event_ids: list<int>, total: int, venues: int}|null
     *                                                                                                                                      null — состав рассыпался (события удалены или прошли) либо темы больше нет: решать вызывающему
     */
    public function recompose(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        Carbon $publishAt,
    ): ?array {
        $slug = $item->digestTheme();
        $cityId = $broadcast->chat?->city_id;
        if ($slug === null || ! $cityId) {
            return null;
        }

        $theme = collect((array) config('broadcast_digest.themes', []))
            ->first(fn ($t) => (string) ($t['slug'] ?? '') === $slug);
        if ($theme === null) {
            return null;
        }

        $named = $this->namedFromLinks($item, $publishAt);
        $roster = DB::table('telegram.chat_broadcast_item_events')->where('item_id', $item->id)->count();

        // Состав ПОХУДЕЛ — собираем заново, а не выпускаем огрызок. Подборку
        // переносят на другой день, и из тройки выпадают начавшиеся события:
        // защита стояла только на полный ноль, а «было три, стало одно»
        // проходило молча — рубрика «Спектакли недели» выходила с одним
        // названным спектаклем, обещая в подвале два десятка.
        if ($named === [] || count($named) < $roster) {
            return null;
        }

        // Разойтись с замороженным составом счётчики не могут: состав входит в
        // пул, а «20 концертов» — это сколько их всего, а не сколько выбрано.
        $pool = $this->poolForTheme($broadcast, (int) $cityId, (array) $theme, $publishAt, $item->id);
        $rows = $pool['rows'] ?? collect();

        $picked = [
            'theme' => (array) $theme,
            'named' => $named,
            'total' => max($rows->count(), count($named)),
            'venues' => max($rows->pluck('venue_id')->filter()->unique()->count(), 1),
        ];

        $caption = $this->buildCaption($broadcast, $picked, $publishAt, $item);

        return [
            'theme' => $picked['theme'],
            'theme_slug' => $slug,
            'caption' => $caption,
            'hook_budget' => $this->hookBudget,
            'event_ids' => $this->postOrder,
            'total' => $picked['total'],
            'venues' => $picked['venues'],
        ] + $this->windowMeta((array) $theme, $publishAt);
    }

    /**
     * Даты окна для текста модели — только у рубрики, чьё окно начинается не с выхода:
     * неделю от выхода парсер считает сам.
     *
     * @param  array<string, mixed>  $theme
     * @return array{window?: array{from: string, to: string}}
     */
    private function windowMeta(array $theme, Carbon $publishAt): array
    {
        if ((int) ($theme['window_from_days'] ?? 0) <= 0 && ! isset($theme['occasion'])) {
            return [];
        }

        [$since, $until] = $this->themeWindow($theme, $publishAt);

        return ['window' => ['from' => $since->toDateString(), 'to' => $until->toDateString()]];
    }

    /**
     * Названные события — из связи «пост → события», свежими фактами.
     *
     * События, которые успели удалить или отыграть, выпадают: ссылка на
     * удалённое событие ведёт в 404, а «сегодня в 19:00» про то, что кончилось
     * час назад, — враньё в канале.
     *
     * @return list<object>
     */
    private function namedFromLinks(TelegramChatBroadcastItem $item, Carbon $publishAt): array
    {
        $ids = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)
            ->orderBy('position')
            ->pluck('event_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('events as e')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->whereIn('e.id', $ids)
            ->whereNull('e.deleted_at')
            ->where('e.status', 'active')
            // Тем же правилом срока, что и пул: многодневка годится, пока идёт.
            ->where(fn ($q) => PostTiming::applyFits($q, $publishAt, 0, 'e', wholeDay: true))
            ->selectRaw(EventStart::sql('e').' as start_time')
            ->addSelect([
                'e.id', 'e.title', 'e.event_group_id', 'e.venue_id',
                'e.description', 'e.tg_description', 'e.price_min', 'e.price_max', 'e.price_status',
                // price_text — ради «по регистрации»: отдельного поля под это нет,
                // слово живёт только в тексте цены. time_precision — ради событий
                // без времени: у них в start_time полночь, и «сб 00:00» это враньё.
                'e.price_text', 'e.time_precision', 'e.content_kind', 'e.address',
                'v.name as venue_name',
            ])
            ->get()
            ->keyBy(fn ($r) => (int) $r->id);

        // ПОРЯДОК СВЯЗЕЙ — ЭТО РЕШЕНИЕ ЧЕЛОВЕКА, и пересортировка по дате его
        // стирала. У автоотбора сортировка по датам обоснована (см. pickNamed:
        // отбор шёл по полноте карточки, и порядок на выходе был случайным), но
        // сюда она попала копией. Состав, собранный руками, приходит уже в том
        // порядке, в каком его выстроили, — position пишется с единицы подряд.
        $named = [];
        foreach ($ids as $id) {
            if ($rows->has($id)) {
                $named[] = $rows->get($id);
            }
        }

        return $named;
    }

    /**
     * Отбор по одной теме. Возвращает null, если тема не прошла гейт.
     *
     * @param  array<string, string>  $theme
     * @return array{theme: array<string, string>, named: list<object>, total: int, venues: int}|null
     */
    private function pickForTheme(
        TelegramChatBroadcast $broadcast,
        int $cityId,
        array $theme,
        Carbon $publishAt,
        ?int $exceptItemId = null,
    ): ?array {
        $pool = $this->poolForTheme($broadcast, $cityId, $theme, $publishAt, $exceptItemId);
        if ($pool === null) {
            return null;
        }

        $rows = $pool['rows'];
        $total = $rows->count();
        $venues = $rows->pluck('venue_id')->filter()->unique()->count();

        if ($total < (int) ($theme['min_events'] ?? config('broadcast_digest.min_events', 5))
            || $venues < (int) ($theme['min_venues'] ?? config('broadcast_digest.min_venues', 3))) {
            return null;
        }

        return [
            'theme' => $theme,
            'named' => $this->pickNamed($rows, $theme),
            'total' => $total,
            'venues' => $venues,
        ];
    }

    /**
     * Чем можно заменить позицию в подборке — ТОЛЬКО ЧТЕНИЕ.
     *
     * ЗАЧЕМ. Состав пишется одним махом, операций над отдельной позицией нет:
     * либо мириться с тройкой, либо пересобирать всё заново. Эта выдача
     * отвечает на вопрос дешевле замены: есть ли вообще чем заменять.
     *
     * ПОЧЕМУ ЧЕРЕЗ poolForTheme, А НЕ СВОИМ ЗАПРОСОМ. Пул уже умеет ровно то,
     * что нужно, со всеми отсечками. Свой запрос неизбежно разошёлся бы с
     * автосборкой, и человек выбирал бы из того, что автомат сам не взял бы.
     *
     * $exceptItemId ОБЯЗАТЕЛЕН. Без него запись вычитает сама себя: собственный
     * состав считается «уже показанным», и на выходе получается пул без трёх
     * событий, которые мы как раз и собираемся заменять.
     *
     * ПОМЕТКИ, А НЕ ФИЛЬТР. Правила «одна площадка — одна строка» и «один день
     * — одна строка» живут в pickNamed и действуют только на автосборку; состав,
     * записанный извне, их не проверяет никто. Поэтому кандидат, который их
     * нарушит, из списка не убирается — но человек видит, ЧЕМ именно он спорит
     * с оставшимися строками. Выбор всё равно за ним: иногда два спектакля в
     * один день лучше, чем один хороший и один никакой.
     *
     * @return array{theme_slug: string, named: list<object>, rows: list<array<string, mixed>>, in_feed: list<array<string, mixed>>}|null
     */
    public function candidatesForItem(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        Carbon $publishAt,
        ?int $replacing = null,
    ): ?array {
        $slug = $item->digestTheme();
        $cityId = $broadcast->chat?->city_id;
        $theme = $this->themeOf($item);
        if ($theme === null || ! $cityId) {
            return null;
        }

        // Состав — теми же свежими фактами, что увидит подпись: у события могли
        // поменять площадку или день, и пометки обязаны считаться от того, что
        // выйдет в канал, а не от того, что лежало в связи на момент сборки.
        $named = $this->namedFromLinks($item, $publishAt);
        $taken = array_map(static fn ($e) => (int) $e->id, $named);

        // Скрытое для канала автомат не берёт, а человеку показываем с пометкой: решает он.
        $pool = $this->poolForTheme($broadcast, (int) $cityId, $theme, $publishAt, $item->id, keepHidden: true);
        $rows = $pool['rows'] ?? collect();

        [$venueAt, $dayAt] = $this->rosterSpots($named, $replacing);

        $out = [];
        foreach ($rows as $row) {
            if (in_array((int) $row->id, $taken, true)) {
                continue;
            }

            $out[] = $this->candidateRow($row, $venueAt, $dayAt);
        }

        // Порядок тот же, которым думает автосборка: сначала то, что встанет в
        // подборку без споров, внутри — по полноте карточки. Спорные не прячем,
        // а опускаем вниз.
        usort($out, function (array $a, array $b) {
            if ($a['clash'] !== $b['clash']) {
                return $a['clash'] <=> $b['clash'];
            }
            if ($a['has_own_text'] !== $b['has_own_text']) {
                return $b['has_own_text'] <=> $a['has_own_text'];
            }

            return $b['description_length'] <=> $a['description_length'];
        });

        // Второй список: события темы, которые канал УЖЕ показывает своим
        // постом. Назвать такое в подборке — дубль, поэтому в обычные
        // кандидаты они не попадают. Но обмен осмыслен: событие переезжает в
        // подборку, а пост снимается. Цена разная — значит и список отдельный.
        $withShown = $this->poolForTheme($broadcast, (int) $cityId, $theme, $publishAt, $item->id, true, keepHidden: true);
        $fresh = array_map(static fn (array $r) => $r['id'], $out);

        $inFeed = [];
        foreach (($withShown['rows'] ?? collect()) as $row) {
            $id = (int) $row->id;
            if (in_array($id, $taken, true) || in_array($id, $fresh, true)) {
                continue;
            }

            // Только то, что ДЕЙСТВИТЕЛЬНО стоит в ленте: отсечка снимает ещё и
            // отправленное за неделю, а его менять местами не на что.
            $post = DB::table('telegram.chat_broadcast_item_events as l')
                ->join('telegram.chat_broadcast_items as i', 'i.id', '=', 'l.item_id')
                ->where('i.broadcast_id', $broadcast->id)
                ->where('l.event_id', $id)
                ->whereNull('i.posted_at')
                // Только обычный пост: снять ради одной строки чужую подборку
                // значило бы потерять ещё два события заодно.
                ->where('i.kind', TelegramChatBroadcastItem::KIND_EVENT)
                // И только не взятый на отправку: у заклеймленного подпись уже
                // уехала боту, и обмен выпустил бы событие дважды.
                ->where(function ($q) {
                    $q->whereNull('i.claimed_at')
                        ->orWhere('i.claimed_at', '<', Carbon::now()->subSeconds(TelegramChatBroadcastService::CLAIM_LEASE_SECONDS));
                })
                ->whereIn('i.status', [
                    TelegramChatBroadcastItem::STATUS_PENDING,
                    TelegramChatBroadcastItem::STATUS_PLANNED,
                    TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                    TelegramChatBroadcastItem::STATUS_APPROVED,
                    TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                ])
                ->orderBy('i.publish_at')
                ->first(['i.id as item_id', 'i.publish_at', 'i.is_pinned']);

            if ($post === null) {
                continue;
            }

            $inFeed[] = [
                'id' => $id,
                'title' => (string) $row->title,
                'start_time' => $row->start_time ? Carbon::parse($row->start_time)->toIso8601String() : null,
                'venue' => $row->venue_name,
                'venue_id' => $row->venue_id !== null ? (int) $row->venue_id : null,
                'has_own_text' => trim((string) $row->tg_description) !== '',
                'post_item_id' => (int) $post->item_id,
                'post_publish_at' => $post->publish_at ? Carbon::parse($post->publish_at)->toIso8601String() : null,
                'post_pinned' => (bool) $post->is_pinned,
            ];
        }

        return ['theme_slug' => $slug, 'named' => $named, 'rows' => $out, 'in_feed' => $inFeed];
    }

    /**
     * События рубрики записи вместе с теми, что канал уже показывает, — для панели выбора в админке.
     *
     * @return \Illuminate\Support\Collection<int, object>|null
     */
    public function poolForItem(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast, Carbon $publishAt): ?\Illuminate\Support\Collection
    {
        $theme = $this->themeOf($item);
        $cityId = $broadcast->chat?->city_id;
        if ($theme === null || ! $cityId) {
            return null;
        }

        return $this->poolForTheme($broadcast, (int) $cityId, $theme, $publishAt, $item->id, true, keepHidden: true)['rows'] ?? collect();
    }

    /**
     * Пометки кандидатов для любых событий, не только из пула: поиск в админке находит и чужое рубрике.
     *
     * @param  list<int>  $eventIds
     * @return array<int, list<string>>
     */
    public function notesForEvents(TelegramChatBroadcastItem $item, Carbon $publishAt, array $eventIds, ?int $replacing = null): array
    {
        if ($eventIds === []) {
            return [];
        }

        [$venueAt, $dayAt] = $this->rosterSpots($this->namedFromLinks($item, $publishAt), $replacing);
        [$hiddenSql, $hiddenBindings] = Event::channelHiddenSql('e');

        $out = [];
        foreach (DB::table('events as e')
            ->whereIn('e.id', $eventIds)
            ->select(['e.id', 'e.title', 'e.venue_id', 'e.description', 'e.tg_description', 'e.content_kind'])
            ->selectRaw(EventStart::sql('e').' as start_time')
            ->selectRaw('null as venue_name')
            ->selectRaw($hiddenSql.' as channel_hidden', $hiddenBindings)
            ->get() as $row) {
            $out[(int) $row->id] = $this->candidateRow($row, $venueAt, $dayAt)['notes'];
        }

        return $out;
    }

    /** Пояснения к правилам отбора для журнала правок; ключи — имена условий в `poolForTheme`. */
    public const MISS_NOTES = [
        'new' => 'появилось в афише после сборки',
        'gone' => 'снято с публикации',
        'city' => 'другой город',
        'window' => 'вне дат рубрики',
        'no_time' => 'без часа начала',
        'timing' => 'слишком близко к выходу или уже закончилось',
        'interest' => 'основная тема события не из этой рубрики',
        'price' => 'не подходит рубрике по цене',
        'hour' => 'начинается раньше часа рубрики',
        'words' => 'в названии и начале описания нет слов праздника',
        'started' => 'началось до выхода подборки',
        'sold_out' => 'билеты распроданы',
        'hidden' => 'скрыто для канала',
        'base' => 'не прошло условия рубрики',
        'stop_list' => 'название из стоп-листа',
        'views' => 'мало просмотров на сайте',
        'access' => 'не подходит рубрике по тому, как на него попасть',
        'genre' => 'в начале описания назван другой жанр',
        'source_rubric' => 'источник относит его к другой рубрике',
        'rejected' => 'недавно отклонено или снято из ленты',
        'shown' => 'канал уже показал или покажет его своим постом',
        'repeat' => 'повтор события, которое уже есть в списке',
        'no_venue' => 'без площадки автомат строку не ставит',
        'rank' => 'было среди кандидатов, автомат выбрал другие',
    ];

    /**
     * Почему автомат сам не взял событие в эту подборку: первое правило отбора, на котором оно выпало.
     * `$pickedAt` — когда автомат выбирал состав: событие новее него он видеть не мог.
     *
     * @return array{rule: string, note: string}|null null — у записи нет рубрики, судить не по чему
     */
    public function missReason(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast, Carbon $publishAt, int $eventId, ?Carbon $pickedAt = null): ?array
    {
        $theme = $this->themeOf($item);
        $cityId = $broadcast->chat?->city_id;
        if ($theme === null || ! $cityId) {
            return null;
        }

        $event = DB::table('events')->where('id', $eventId)->first(['created_at', 'content_kind', 'description']);
        if ($event === null) {
            return null;
        }
        if ($pickedAt !== null && $event->created_at !== null && Carbon::parse($event->created_at)->gt($pickedAt)) {
            return ['rule' => 'new', 'note' => self::MISS_NOTES['new']];
        }

        $this->tracing = $eventId;
        $this->lostAt = null;
        try {
            $pool = $this->poolForTheme($broadcast, (int) $cityId, $theme, $publishAt, $item->id);
        } finally {
            $this->tracing = null;
        }
        if ($pool === null) {
            return null;
        }

        $rule = $this->lostAt;
        if ($rule === 'hidden') {
            return ['rule' => $rule, 'note' => self::hiddenNote($event->content_kind)];
        }
        if ($rule !== null) {
            return ['rule' => $rule, 'note' => self::MISS_NOTES[$rule]];
        }

        $ranked = $this->rankForTheme($pool['rows'], $theme);
        foreach ($ranked as $i => $row) {
            if ((int) $row->id !== $eventId) {
                continue;
            }
            $note = sprintf('было среди кандидатов: %d-е по интересу из %d', $i + 1, count($ranked));
            $min = (int) config('broadcast_digest.min_description', 120);
            if (mb_strlen(trim((string) $event->description)) < $min) {
                $note .= ", описание короче {$min} знаков";
            }

            return ['rule' => 'rank', 'note' => $note];
        }

        return ['rule' => 'no_venue', 'note' => self::MISS_NOTES['no_venue']];
    }

    /**
     * Окно дат рубрики записи; null у начала — окно от момента выхода.
     *
     * @return array{0: Carbon|null, 1: Carbon}|null
     */
    public function windowForItem(TelegramChatBroadcastItem $item, Carbon $publishAt): ?array
    {
        $theme = $this->themeOf($item);

        return $theme === null ? null : $this->themeWindow($theme, $publishAt);
    }

    /** Почему автомат не ставит скрытое для канала событие (`Event::channelHiddenSql`). */
    public static function hiddenNote(?string $contentKind): string
    {
        return match ($contentKind) {
            'official' => 'официоз',
            'religious' => 'религия',
            'patriotic_ceremony' => 'памятная церемония',
            default => 'памятная дата',
        }.' — автомат такое в канал не ставит';
    }

    /** @return array<string, mixed>|null рубрика записи из конфига */
    private function themeOf(TelegramChatBroadcastItem $item): ?array
    {
        $slug = $item->digestTheme();
        if ($slug === null) {
            return null;
        }

        $theme = collect((array) config('broadcast_digest.themes', []))
            ->first(fn ($t) => (string) ($t['slug'] ?? '') === $slug);

        return $theme === null ? null : (array) $theme;
    }

    /**
     * Площадки и дни занятых строк — с номером строки: «та же площадка, что во второй» полезнее, чем
     * «площадка занята». Заменяемая строка в споре не участвует, она уходит: иначе другой спектакль
     * того же театра вместо этого получал бы ложное «та же площадка».
     *
     * @param  list<object>  $named
     * @return array{0: array<int, int>, 1: array<string, int>}
     */
    private function rosterSpots(array $named, ?int $replacing): array
    {
        $venueAt = [];
        $dayAt = [];
        foreach ($named as $i => $row) {
            if ($replacing !== null && (int) $row->id === $replacing) {
                continue;
            }
            if ($row->venue_id !== null) {
                $venueAt[(int) $row->venue_id] ??= $i + 1;
            }
            $day = Carbon::parse($row->start_time)->setTimezone(self::TZ)->toDateString();
            $dayAt[$day] ??= $i + 1;
        }

        return [$venueAt, $dayAt];
    }

    /**
     * Кандидат в строку подборки с пометками: чем он спорит с оставшимися строками.
     *
     * @param  array<int, int>  $venueAt
     * @param  array<string, int>  $dayAt
     * @return array<string, mixed>
     */
    private function candidateRow(object $row, array $venueAt, array $dayAt): array
    {
        $venue = $row->venue_id !== null ? (int) $row->venue_id : null;
        $day = Carbon::parse($row->start_time)->setTimezone(self::TZ)->toDateString();
        $length = mb_strlen(trim((string) $row->description));
        $hidden = (bool) $row->channel_hidden;

        $notes = [];
        if ($hidden) {
            $notes[] = self::hiddenNote($row->content_kind);
        }
        // Единственное жёсткое правило автосборки — см. pickNamed.
        if ($venue === null) {
            $notes[] = 'без площадки — автомат такое не называет';
        }
        if ($venue !== null && isset($venueAt[$venue])) {
            $notes[] = 'та же площадка, что в строке '.$venueAt[$venue];
        }
        if (isset($dayAt[$day])) {
            $notes[] = 'тот же день, что в строке '.$dayAt[$day];
        }
        if (trim((string) $row->tg_description) !== '') {
            $notes[] = 'есть свой анонс';
        } elseif ($length < (int) config('broadcast_digest.min_description', 120)) {
            $notes[] = 'короткая карточка — строка выйдет сухой';
        }

        return [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'start_time' => $row->start_time ? Carbon::parse($row->start_time)->toIso8601String() : null,
            'has_time' => ($row->time_precision ?? 'datetime') !== 'date',
            'venue' => $row->venue_name,
            'venue_id' => $venue,
            'description_length' => $length,
            'has_own_text' => trim((string) $row->tg_description) !== '',
            'clash' => $hidden || $venue !== null && isset($venueAt[$venue]) || isset($dayAt[$day]) || $venue === null,
            'notes' => $notes,
        ];
    }

    /**
     * Пул темы после всех отсечек — то, из чего выбирается тройка и что
     * считается в «20 концертов на 10 площадках».
     *
     * @param  array<string, string>  $theme
     * @param  int|null  $exceptItemId  чью связь не считать чужой: собственный
     *                                  состав записи не должен вычитать сам себя
     */
    private function poolForTheme(
        TelegramChatBroadcast $broadcast,
        int $cityId,
        array $theme,
        Carbon $publishAt,
        ?int $exceptItemId = null,
        bool $keepShown = false,
        bool $keepHidden = false,
    ): ?array {
        // РУБРИКА ОТБИРАЕТ НЕ ТОЛЬКО ПО ТЕМЕ. «Спектакли» — это интерес, а
        // «Бесплатно» и «Дешевле 500» — цена, «Вечером» — час начала. Признак
        // разный, всё остальное (срок, отсечки, схлопывание повторов)
        // одинаковое, поэтому развилка живёт здесь, а не в отдельном пуле.
        $pick = (string) ($theme['pick'] ?? 'interest');

        // 'all' — отбор без фильтра, одно только окно; на нём стоит рубрика
        // «На выходных». Перечень закрытый НАМЕРЕННО: без него опечатка в
        // конфиге («fre» вместо «free») тихо превращала бы рубрику про деньги
        // в подборку всего подряд, и заметить это можно было бы только в
        // канале.
        if (! in_array($pick, ['interest', 'free', 'price_max', 'tod', 'all', 'ahead', 'words', 'views', 'access'], true)) {
            Log::warning('broadcast.digest.unknown_pick', [
                'theme' => $theme['slug'] ?? null, 'pick' => $pick,
            ]);

            return null;
        }

        $ids = [];
        $roots = match ($pick) {
            'interest' => [(string) $theme['interest']],
            'ahead' => array_map('strval', (array) ($theme['kinds'] ?? [])),
            default => [],
        };
        if ($roots !== []) {
            $ids = array_values(array_unique(array_merge(...array_map(fn ($slug) => $this->interestTree($slug), $roots))));
            if ($ids === []) {
                return null;
            }
        }

        // Окно у рубрики может быть своё — и по длине, и по виду. Бесплатное
        // объявляют поздно: замер 25.09.2026 — 35 событий в ближайшую неделю и
        // 4 в следующую, поэтому недельное окно для него почти пустое.
        [$since, $until] = $this->themeWindow((array) $theme, $publishAt);
        [$hiddenSql, $hiddenBindings] = Event::channelHiddenSql('e');

        // условия по одному, с именем правила: по ним `missReason` находит, какое не прошло событие
        $filters = array_filter([
            'gone' => fn ($q) => $q->whereNull('e.deleted_at')->where('e.status', 'active'),
            'city' => fn ($q) => $q->where('c.city_id', $cityId),
            // нижняя граница только у календарного окна: у «выходных» без неё попадал бы вечер пятницы
            'window' => fn ($q) => $q->where(fn ($w) => EventStart::notAfter($w, $until, 'e'))
                ->when($since !== null, fn ($w) => EventStart::notBefore($w, $since, 'e')),
            // часам повода (`hours`) событие без часа не годится: неизвестно, ночью ли оно
            'no_time' => isset($theme['hours']) ? fn ($q) => $q->where('e.time_precision', '<>', 'date') : null,
            // срок общим правилом (`PostTiming`): многодневку берём до закрытия, иначе «Выставки недели»
            // не назвали бы выставку, открытую неделю назад
            'timing' => fn ($q) => $q->where(fn ($w) => PostTiming::applyFits($w, $publishAt, PostTiming::MIN_LEAD_HOURS, 'e', wholeDay: true)),
            // только основной интерес: иначе в спектакли попадёт концерт, у которого театр вторым тегом
            'interest' => $ids === [] ? null : fn ($q) => $q->join('event_interest as ei', 'ei.event_id', '=', 'e.id')
                ->where('ei.rank', 0)
                ->whereIn('ei.interest_id', $ids),
            'price' => match ($pick) {
                // «вход свободный, кто сколько может» читатель тоже считает бесплатным
                'free' => fn ($q) => $q->whereIn('e.price_status', ['free', 'donation']),
                // только известная цена: обещание «дешевле 500» проверят первым же нажатием
                'price_max' => fn ($q) => $q->whereNotNull('e.price_min')
                    ->where('e.price_min', '>', 0)
                    ->where('e.price_min', '<=', (int) ($theme['price_max'] ?? 500)),
                // бесплатное заранее не покупают; платное — это цена, продажа на чужом сайте или касса среди источников
                'ahead' => fn ($q) => $q->whereNotIn('e.price_status', ['free', 'donation'])
                    ->where(fn ($w) => $w->where('e.price_min', '>', 0)
                        ->orWhere('e.price_status', 'external')
                        ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('event_sources as es')
                            ->whereColumn('es.event_id', 'e.id')
                            ->whereIn('es.source', Scoring\EventInterestSignals::TICKET_SOURCES))),
                default => null,
            },
            'hour' => $pick === 'tod' ? fn ($q) => $q->whereRaw(
                "EXTRACT(HOUR FROM e.start_time AT TIME ZONE 'Europe/Moscow') >= ?",
                [(int) ($theme['hour_from'] ?? 20)],
            ) : null,
            // повод по словам в названии или в начале описания: «Кошмар на улице Кирова» называет Хэллоуин только там
            // у «всего подряд» слов нет, но темы и исключения повода действуют: школьные каникулы — детское
            'words' => $pick === 'words' || $pick === 'all' ? fn ($q) => OccasionCalendar::applyWords($q, $theme, 'e') : null,
            // рубрике не по интересу нужно начало впереди: иначе «Вечером» назовёт спектакль, начавшийся месяц назад
            'started' => $pick === 'interest' ? null : fn ($q) => EventStart::notBefore($q, $publishAt, 'e'),
            'sold_out' => fn ($q) => $q->where(fn ($w) => $w->whereNull('e.tickets_status')->orWhere('e.tickets_status', '<>', 'sold_out')),
            'hidden' => $keepHidden ? null : fn ($q) => $q->whereRaw('not '.$hiddenSql, $hiddenBindings),
        ]);

        $query = self::poolQuery();
        foreach ($filters as $filter) {
            $filter($query);
        }

        $rows = $query
            ->orderByRaw(EventStart::sql('e'))
            ->select([
                'e.id', 'e.title', 'e.event_group_id', 'e.venue_id',
                'e.description', 'e.tg_description', 'e.price_min', 'e.price_max', 'e.price_status',
                // price_text — ради «по регистрации»: отдельного поля под это нет,
                // слово живёт только в тексте цены. time_precision — ради событий
                // без времени: у них в start_time полночь, и «сб 00:00» это враньё.
                'e.price_text', 'e.time_precision', 'e.content_kind',
                // для оценки интереса в pickNamed: прокат или разовое, детское ли
                'e.end_time', 'e.audience',
                // место события без площадки — у повода, см. `address_as_place`
                'e.address',
                'v.name as venue_name',
            ])
            ->selectRaw(EventStart::sql('e').' as start_time')
            ->selectRaw($hiddenSql.' as channel_hidden', $hiddenBindings)
            ->get();
        $this->traceBase($rows, $filters);

        if ($theme['stop_list'] ?? true) {
            $rows = $this->rejectStopList($rows);
            $this->traceLoss($rows, 'stop_list');
        }
        if ($pick === 'views') {
            $rows = $this->withViews($rows, $theme, $publishAt);
            $this->traceLoss($rows, 'views');
        }
        if ($pick === 'access') {
            $this->scorer->prepare($rows);
            $rows = $rows->filter(fn ($r) => in_array($this->scorer->access($r), (array) ($theme['access'] ?? []), true))->values();
            $this->traceLoss($rows, 'access');
        }

        // Жанровые отсечки — ТОЛЬКО тематическим рубрикам. Они спрашивают «не
        // чужой ли это жанр для темы», а у «Бесплатно» и «Дешевле 500» жанра
        // нет вовсе: там годится и концерт, и лекция, и экскурсия. Оставь их
        // включёнными — и рубрика выкосит сама себя, потому что у каждого
        // события в описании найдётся слово чужого жанра.
        if ($pick === 'interest') {
            $rows = $this->rejectForeignGenre($rows, (string) $theme['slug']);
            $this->traceLoss($rows, 'genre');
            $rows = $this->rejectForeignSourceRubric($rows, (string) $theme['slug']);
            $this->traceLoss($rows, 'source_rubric');
        }
        // Остывание отказа — и в подборке тоже: см. rejectRecentlyRejected.
        $rows = $this->rejectRecentlyRejected($broadcast, $rows);
        $this->traceLoss($rows, 'rejected');

        // $keepShown — для выдачи «уже в ленте»: там нужны как раз те события,
        // которые эта отсечка и снимает. Автосборка её не отключает никогда.
        // «Что смотрят» называет и то, о чём канал уже писал: это выбор людей, а не новость
        if (! $keepShown && ($theme['keep_shown'] ?? false) === false) {
            $rows = $this->rejectAlreadyShown($broadcast, $rows, $exceptItemId, $theme);
            $this->traceLoss($rows, 'shown');
        }

        // из повторов остаётся первый, и у двух записей одного дня это та, где есть время
        $rows = $rows->sortBy(fn ($r) => self::dayOrder($r))->values();

        if (! $keepHidden) {
            $rows = $this->collapseRepeats($rows);
            $this->traceLoss($rows, 'repeat');

            return ['rows' => $rows];
        }

        // Из повторов остаётся первый, поэтому открытые ставим вперёд: иначе скрытый
        // двойник вытеснил бы из списка событие, которое автомат взял бы сам.
        [$hidden, $open] = $rows->partition(fn ($r) => (bool) $r->channel_hidden);

        return ['rows' => $this->collapseRepeats($open->concat($hidden))->sortBy('start_time')->values()];
    }

    private static function poolQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('events as e')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->join('communities as c', 'c.id', '=', 'e.community_id');
    }

    /**
     * Искомое событие не попало в выборку: проверяем его каждым условием по отдельности.
     *
     * @param  array<string, \Closure>  $filters
     */
    private function traceBase(\Illuminate\Support\Collection $rows, array $filters): void
    {
        if ($this->tracing === null || $rows->contains(fn ($r) => (int) $r->id === $this->tracing)) {
            return;
        }

        foreach ($filters as $rule => $filter) {
            $one = self::poolQuery()->where('e.id', $this->tracing);
            $filter($one);
            if (! $one->exists()) {
                $this->lostAt = $rule;

                return;
            }
        }

        $this->lostAt = 'base';
    }

    private function traceLoss(\Illuminate\Support\Collection $rows, string $rule): void
    {
        if ($this->tracing !== null && $this->lostAt === null && ! $rows->contains(fn ($r) => (int) $r->id === $this->tracing)) {
            $this->lostAt = $rule;
        }
    }

    /**
     * Сколько событий и площадок набирает рубрика к моменту выхода, теми же отсечками, что сборка поста.
     *
     * @param  array<string, mixed>  $theme
     * @return array{events: int, venues: int}
     */
    public function poolSize(TelegramChatBroadcast $broadcast, array $theme, Carbon $publishAt): array
    {
        $cityId = $broadcast->chat?->city_id;
        $rows = $cityId ? ($this->poolForTheme($broadcast, (int) $cityId, $theme, $publishAt)['rows'] ?? collect()) : collect();

        return [
            'events' => $rows->count(),
            'venues' => $rows->map(fn ($r) => $r->venue_id ?: mb_strtolower((string) ($r->address ?? '')))->filter()->unique()->count(),
        ];
    }

    /**
     * Строки с числом людей, открывших карточку за `views_days` суток (свой счётчик, см. `EventViews`);
     * у кого меньше `min_views`, тот не в счёт: два-три захода — это ещё не интерес.
     */
    private function withViews(\Illuminate\Support\Collection $rows, array $theme, Carbon $publishAt): \Illuminate\Support\Collection
    {
        $views = \App\Support\EventViews::visitors(
            $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            (int) ($theme['views_days'] ?? 7),
            $publishAt,
        );
        $min = (int) ($theme['min_views'] ?? 3);

        return $rows->filter(fn ($row) => ($views[(int) $row->id] ?? 0) >= $min)
            ->each(function ($row) use ($views) {
                $row->views = $views[(int) $row->id];
            })
            ->values();
    }

    /** Дерево интересов темы — тем же рекурсивным обходом, что и лендинг. */
    public static function interestTree(string $slug): array
    {
        $rows = DB::select(
            'WITH RECURSIVE picked AS (
                SELECT id FROM interests WHERE slug = ?
                UNION
                SELECT i.id FROM interests i JOIN picked p ON i.parent_id = p.id
            )
            SELECT id FROM picked',
            [$slug]
        );

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /** Заголовки не по теме — причина у `title_stop_list` в config/broadcast_digest.php. */
    private function rejectStopList(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        $stop = (array) config('broadcast_digest.title_stop_list', []);

        return $rows->reject(function ($row) use ($stop) {
            $title = mb_strtolower(trim((string) $row->title));
            foreach ($stop as $word) {
                if (str_starts_with($title, (string) $word)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Событие, которое в первых словах описания называет ЧУЖОЙ жанр.
     *
     * Причина и замеры — у `genre_words` в config/broadcast_digest.php.
     */
    private function rejectForeignGenre(\Illuminate\Support\Collection $rows, string $themeSlug): \Illuminate\Support\Collection
    {
        $words = (array) config('broadcast_digest.genre_words', []);
        $limit = (int) config('broadcast_digest.genre_lookup_chars', 150);

        return $rows->reject(function ($row) use ($words, $themeSlug, $limit) {
            $head = mb_strtolower(mb_substr(trim((string) $row->description), 0, $limit));
            if ($head === '') {
                return false;
            }

            foreach ($words as $word => $slug) {
                if ($slug !== $themeSlug && mb_strpos($head, (string) $word) !== false) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Событие, чью категорию НАЗВАЛ САМ ИСТОЧНИК, и она спорит с темой.
     *
     * Почему источнику верим больше, чем тегеру, — у `source_rubrics` в
     * config/broadcast_digest.php. Зачем вдобавок к проверке описания: та ловит
     * только тех, кто называет жанр в первой строке, — меньше трети случаев,
     * а источник знает про все.
     */
    private function rejectForeignSourceRubric(\Illuminate\Support\Collection $rows, string $themeSlug): \Illuminate\Support\Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $map = (array) config('broadcast_digest.source_rubrics', []);
        if ($map === []) {
            return $rows;
        }

        $rubrics = DB::table('event_sources as es')
            ->join('context_posts as cp', 'cp.id', '=', 'es.context_post_id')
            ->whereIn('es.event_id', $rows->pluck('id')->all())
            ->whereNotNull(DB::raw("cp.structured_meta->'json_ld'->>'url'"))
            ->selectRaw("es.event_id, split_part(cp.structured_meta->'json_ld'->>'url', '/', 5) as rubric")
            ->get()
            ->groupBy('event_id')
            ->map(fn ($g) => (string) $g->first()->rubric);

        return $rows->reject(function ($row) use ($rubrics, $map, $themeSlug) {
            $rubric = $rubrics[(int) $row->id] ?? null;
            if ($rubric === null || ! isset($map[$rubric])) {
                return false; // источник промолчал — судим по нашему тегу
            }

            return $map[$rubric] !== $themeSlug;
        })->values();
    }

    /**
     * Событие, от которого канал недавно отказался или которое сняли из ленты руками, рубрика не предлагает.
     *
     * Тот же срок и тот же признак, что у ленты: запись со статусом rejected,
     * тронутая за последние REJECTED_COOLDOWN_DAYS суток. Второго механизма
     * заводить нельзя — «не предлагать» должно означать одно и то же в ленте и
     * в подборке, иначе человек прячет событие в одном месте и встречает его в
     * другом.
     */
    private function rejectRecentlyRejected(
        TelegramChatBroadcast $broadcast,
        \Illuminate\Support\Collection $rows,
    ): \Illuminate\Support\Collection {
        $since = Carbon::now()->subDays(TelegramChatBroadcastService::REJECTED_COOLDOWN_DAYS);

        // Через СВЯЗЬ, а не по колонке items.event_id: у подборки колонка пуста
        // по построению, и отказ от подборки не остужал бы ни одно из трёх
        // названных ею событий. Тот же джойн, что у rejectAlreadyShown.
        $cold = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('telegram.chat_broadcast_items as i', 'i.id', '=', 'l.item_id')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('i.broadcast_id', $broadcast->id)
            ->where('i.status', TelegramChatBroadcastItem::STATUS_REJECTED)
            ->where('i.updated_at', '>=', $since)
            ->get(['e.id', 'e.event_group_id', 'e.title']);

        // снятое руками — те же правила, что в `TelegramChatBroadcastService::channelCandidates`
        $removedIds = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('telegram.chat_broadcast_items as i', 'i.id', '=', 'l.item_id')
            ->where('i.broadcast_id', $broadcast->id)
            ->where('i.kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->where('i.status', TelegramChatBroadcastItem::STATUS_SKIPPED)
            ->where('i.error_message', TelegramChatBroadcastItem::REMOVED_BY_HAND)
            ->pluck('l.event_id')->map(fn ($v) => (int) $v)->all();
        $pausedGroups = DB::table('telegram.chat_broadcast_items as i')
            ->join('events as e', 'e.id', '=', 'i.event_id')
            ->where('i.broadcast_id', $broadcast->id)
            ->whereIn('i.status', [TelegramChatBroadcastItem::STATUS_SKIPPED, TelegramChatBroadcastItem::STATUS_REJECTED])
            ->whereNotNull('e.event_group_id')
            ->whereRaw(
                "(i.pick_meta->>'".TelegramChatBroadcastItem::PICK_REMOVED_AT."')::timestamptz >= ?::timestamptz",
                [Carbon::now()->subDays(TelegramChatBroadcastService::REMOVED_SERIES_PAUSE_DAYS)->toIso8601String()],
            )
            ->pluck('e.event_group_id')->map(fn ($v) => (int) $v)->all();

        if ($cold->isEmpty() && $removedIds === [] && $pausedGroups === []) {
            return $rows;
        }

        // И по трём ключам, а не по одному id. Пул схлопывает повторы, но
        // ПОСЛЕ этой отсечки: выброшенный спектакль вернулся бы следующей
        // датой того же названия сразу же, и кнопка «больше не предлагать»
        // выглядела бы сломанной.
        $ids = [...$cold->pluck('id')->map(fn ($v) => (int) $v)->all(), ...$removedIds];
        $groups = [...$cold->pluck('event_group_id')->filter()->map(fn ($v) => (int) $v)->all(), ...$pausedGroups];
        $titles = $cold->pluck('title')->map(fn ($t) => $this->titleKey((string) $t))->filter()->all();

        return $rows->reject(function ($r) use ($ids, $groups, $titles) {
            if (in_array((int) $r->id, $ids, true)) {
                return true;
            }
            if ($r->event_group_id !== null && in_array((int) $r->event_group_id, $groups, true)) {
                return true;
            }

            return in_array($this->titleKey((string) $r->title), $titles, true);
        })->values();
    }

    /**
     * Вычесть то, что канал уже показал или вот-вот покажет.
     *
     * По ТРЁМ ключам: сам идентификатор, группа повторов и нормализованный
     * заголовок. Одного мало — то же событие приезжает из разных источников
     * разными строками, и подборка стала бы оглавлением уже прочитанного.
     */
    private function rejectAlreadyShown(
        TelegramChatBroadcast $broadcast,
        \Illuminate\Support\Collection $rows,
        ?int $exceptItemId = null,
        array $theme = [],
    ): \Illuminate\Support\Collection {
        // рубрика раз в две недели не повторяет своё: окна соседних выпусков перекрываются
        $ownSlug = empty($theme['every_days']) ? null : (string) $theme['slug'];
        $ownDays = (int) ($theme['window_days'] ?? self::SHOWN_WINDOW_DAYS);

        $shown = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('telegram.chat_broadcast_items as i', 'i.id', '=', 'l.item_id')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('i.broadcast_id', $broadcast->id)
            // Собственный состав записи — не «уже показанное». Без этого
            // повторная сборка той же подборки НИКОГДА не выбирает ту же
            // тройку: она только что сама её и закрыла.
            ->when($exceptItemId !== null, fn ($q) => $q->where('i.id', '<>', $exceptItemId))
            ->where(function ($q) use ($ownSlug, $ownDays) {
                $q->whereIn('i.status', [
                    TelegramChatBroadcastItem::STATUS_PENDING,
                    TelegramChatBroadcastItem::STATUS_PLANNED,
                    TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                    TelegramChatBroadcastItem::STATUS_APPROVED,
                    TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                ])->orWhere(function ($w) {
                    $w->where('i.status', TelegramChatBroadcastItem::STATUS_POSTED)
                        ->where('i.posted_at', '>=', Carbon::now()->subDays(self::SHOWN_WINDOW_DAYS));
                })->when($ownSlug !== null, fn ($q) => $q->orWhere(fn ($w) => $w
                    ->where('i.status', TelegramChatBroadcastItem::STATUS_POSTED)
                    ->where('i.posted_at', '>=', Carbon::now()->subDays($ownDays))
                    ->whereRaw("i.digest_meta->>'theme' = ?", [$ownSlug])));
            })
            ->get(['e.id', 'e.event_group_id', 'e.title']);

        $ids = $shown->pluck('id')->map(fn ($v) => (int) $v)->all();
        $groups = $shown->pluck('event_group_id')->filter()->map(fn ($v) => (int) $v)->all();
        $titles = $shown->pluck('title')->map(fn ($t) => $this->titleKey((string) $t))->all();

        return $rows->reject(fn ($row) => in_array((int) $row->id, $ids, true)
            || ($row->event_group_id !== null && in_array((int) $row->event_group_id, $groups, true))
            || in_array($this->titleKey((string) $row->title), $titles, true))->values();
    }

    /**
     * Схлопнуть повторы: сначала по группе, затем по заголовку и дню. Второе
     * нужно там, где дедуп не справился и один спектакль лежит двумя группами.
     */
    private function collapseRepeats(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        $seenGroups = [];
        $seenTitles = [];

        return $rows->reject(function ($row) use (&$seenGroups, &$seenTitles) {
            $group = $row->event_group_id;
            if ($group !== null) {
                if (isset($seenGroups[$group])) {
                    return true;
                }
                $seenGroups[$group] = true;
            }

            $key = $this->titleKey((string) $row->title);
            if (isset($seenTitles[$key])) {
                return true;
            }
            $seenTitles[$key] = true;

            return false;
        })->values();
    }

    /**
     * Кого назвать поимённо: самых интересных по оценке ленты (`EventBroadcastScorer::rankRows`), с
     * разными площадками, у недельных рубрик — и с разными днями. Вид, который уже назван, ждёт, пока не
     * кончатся другие; на выходных называем оба дня; бесплатное или дешёвое встаёт, если его не набралось.
     *
     * @param  array<string, mixed>  $theme  рубрика: сколько назвать, правила дня и цены, порядок
     * @return list<object>
     */
    private function pickNamed(\Illuminate\Support\Collection $rows, array $theme): array
    {
        [$min, $max] = self::namedRange($theme);
        $onePerDay = (bool) ($theme['one_per_day'] ?? true);
        $bothDays = ($theme['window'] ?? '') === 'weekend';
        $ranked = $this->rankForTheme($rows, $theme);

        /** @var array<int, object> $named */
        $named = [];

        if ($bothDays) {
            foreach (array_unique(array_map(fn ($r) => self::dayOf($r), $ranked)) as $day) {
                foreach ($ranked as $row) {
                    // день важнее разнообразия вида: воскресенье без строки хуже двух спектаклей
                    if (self::dayOf($row) === $day && $this->fitsNamed($row, $named, $min, $max, $onePerDay)) {
                        $named[(int) $row->id] = $row;
                        break;
                    }
                }
            }
        }

        $this->kindCaps = array_map('intval', (array) ($theme['kind_caps'] ?? []));
        // сначала строк одного вида не больше `kind_cap`, потом без этого предела, если мест осталось больше
        foreach ([max(1, (int) ($theme['kind_cap'] ?? 1)), 0] as $kindCap) {
            foreach ($ranked as $row) {
                if ($this->fitsNamed($row, $named, $min, $max, $onePerDay, $kindCap)) {
                    $named[(int) $row->id] = $row;
                }
            }
        }

        $named = ($theme['cheap_line'] ?? true)
            ? $this->withCheapLine($named, $ranked, $min, $max, $onePerDay, $bothDays)
            : array_values($named);

        // по датам: подборка отвечает на вопрос «что впереди», и читать её удобно по порядку недели;
        // «Что смотрят» — по числу людей, это и есть её смысл
        if (($theme['pick'] ?? '') !== 'views') {
            usort($named, fn ($a, $b) => self::dayOrder($a) <=> self::dayOrder($b));
        } else {
            usort($named, fn ($a, $b) => (int) ($b->views ?? 0) <=> (int) ($a->views ?? 0));
        }

        return $named;
    }

    /**
     * Кандидаты в строки от самого интересного: в этом порядке их перебирает `pickNamed`.
     *
     * @param  array<string, mixed>  $theme
     * @return list<object>
     */
    private function rankForTheme(\Illuminate\Support\Collection $rows, array $theme): array
    {
        // без площадки не называем: строка без места говорит половину нужного; повод берёт и адрес,
        // вечеринку в баре без карточки площадки тоже ищут
        $byAddress = ! empty($theme['address_as_place']);
        $ranked = $this->scorer->rankRows($rows->filter(
            fn ($r) => $r->venue_id !== null || ($byAddress && trim((string) ($r->address ?? '')) !== ''),
        )->values()->all());
        if (! empty($theme['ties_by_price'])) {
            usort($ranked, fn ($a, $b) => [$this->scorer->interest($b), (int) ($b->price_min ?? 0)]
                <=> [$this->scorer->interest($a), (int) ($a->price_min ?? 0)]);
        }
        if (($theme['pick'] ?? '') === 'views') {
            usort($ranked, fn ($a, $b) => (int) ($b->views ?? 0) <=> (int) ($a->views ?? 0));
        }
        if (isset($theme['occasion'])) {
            $ranked = $this->byOccasion($ranked, (array) ($theme['words'] ?? []));
        }

        return $ranked;
    }

    /** Цена, до которой событие в подборке считается дешёвым. */
    private const CHEAP_MAX = 500;

    /** @var array<string, int> пределы видов текущей рубрики, см. pickNamed */
    private array $kindCaps = [];

    /** Прибавка к оценке события, у которого праздник прямо в названии, см. byOccasion. */
    private const OCCASION_TITLE_BONUS = 20;

    /**
     * Праздник в названии — событие про праздник, а не к нему: «Готический Halloween» с темой «Городские
     * акции» иначе проигрывал квизу. Без часа строка повода всё равно с датой, штраф за это снимаем.
     *
     * @param  list<object>  $ranked
     * @param  list<string>  $words
     * @return list<object>
     */
    private function byOccasion(array $ranked, array $words): array
    {
        $score = function (object $row) use ($words): int {
            return $this->scorer->interest($row)
                + (OccasionCalendar::titleNames((string) $row->title, $words) ? self::OCCASION_TITLE_BONUS : 0)
                - (($row->time_precision ?? null) === 'date' ? Scoring\EventBroadcastScorer::W_NO_TIME : 0);
        };
        usort($ranked, fn ($a, $b) => $score($b) <=> $score($a));

        return $ranked;
    }

    /**
     * Встанет ли строка рядом с уже названными.
     *
     * @param  array<int, object>  $named
     */
    private function fitsNamed(object $row, array $named, int $min, int $max, bool $onePerDay, int $kindCap = 0): bool
    {
        if (isset($named[(int) $row->id]) || count($named) >= $max) {
            return false;
        }

        $kind = $this->scorer->explain($row)['kind'];
        $sameKind = 0;
        foreach ($named as $other) {
            if (self::samePlace($other, $row)) {
                return false;
            }
            // «одна строка на день» — для недельной подборки; на двух днях выходных это потолок в две строки
            if ($onePerDay && self::dayOf($other) === self::dayOf($row)) {
                return false;
            }
            if ($kind !== null && $this->scorer->explain($other)['kind'] === $kind) {
                $sameKind++;
            }
        }
        // 0 — вид не ограничен
        if ($kindCap > 0 && $sameKind >= $kindCap) {
            return false;
        }
        // свой предел вида (`kind_caps`) не снимает и добор: второй квиз хуже строкой меньше
        if ($kind !== null && isset($this->kindCaps[$kind]) && $sameKind >= $this->kindCaps[$kind]) {
            return false;
        }

        // сверх минимума — только с описанием на строку, иначе встанет голое «название · дата · место»
        return count($named) < $min
            || mb_strlen(trim((string) $row->description)) >= (int) config('broadcast_digest.min_description', 120);
    }

    /**
     * Если среди названных нет бесплатного или дешёвого, лучшее такое встаёт в свободное место или
     * вместо самой слабой строки. Единственную строку дня на выходных не трогаем.
     *
     * @param  array<int, object>  $named
     * @param  list<object>  $ranked
     * @return list<object>
     */
    private function withCheapLine(array $named, array $ranked, int $min, int $max, bool $onePerDay, bool $bothDays): array
    {
        $cheap = fn (object $r) => in_array($r->price_status, ['free', 'donation'], true)
            || ((int) ($r->price_min ?? 0) > 0 && (int) $r->price_min <= self::CHEAP_MAX);

        if ($named === [] || array_filter($named, $cheap) !== []) {
            return array_values($named);
        }

        $position = array_flip(array_map(fn ($r) => (int) $r->id, $ranked));
        foreach ($ranked as $row) {
            if (! $cheap($row) || isset($named[(int) $row->id])) {
                continue;
            }
            if ($this->fitsNamed($row, $named, $min, $max, $onePerDay)) {
                $named[(int) $row->id] = $row;
                break;
            }

            $weakest = null;
            foreach ($named as $id => $other) {
                $dayMates = array_filter($named, fn ($n) => self::dayOf($n) === self::dayOf($other));
                if ($bothDays && count($dayMates) === 1) {
                    continue;
                }
                if ($weakest === null || $position[$id] > $position[(int) $weakest->id]) {
                    $weakest = $other;
                }
            }
            if ($weakest === null) {
                break;
            }

            $without = $named;
            unset($without[(int) $weakest->id]);
            if ($this->fitsNamed($row, $without, $min, $max, $onePerDay)) {
                $without[(int) $row->id] = $row;
                $named = $without;
                break;
            }
        }

        return array_values($named);
    }

    /** Одна площадка — одна строка; у события без площадки место — его адрес. */
    private static function samePlace(object $a, object $b): bool
    {
        if ($a->venue_id !== null || $b->venue_id !== null) {
            return (int) $a->venue_id === (int) $b->venue_id;
        }

        return mb_strtolower(trim((string) ($a->address ?? ''))) === mb_strtolower(trim((string) ($b->address ?? '')));
    }

    private static function dayOf(object $row): string
    {
        return Carbon::parse($row->start_time)->setTimezone(self::TZ)->toDateString();
    }

    /** По дням; событие без времени — в конце своего дня: в start_time у него полночь, и оно встало бы перед утренними. */
    private static function dayOrder(object $row): array
    {
        return [self::dayOf($row), ($row->time_precision ?? null) === 'date', (string) $row->start_time];
    }

    /**
     * Сколько событий называть в этой рубрике: [минимум, максимум].
     *
     * У темы может стоять своё `named` — числом или парой. Пара означает
     * «от и до»: сколько получится назвать с полным описанием, столько и
     * назовём (см. pickNamed).
     *
     * @param  array<string, mixed>  $theme
     * @return array{0:int,1:int}
     */
    private static function namedRange(array $theme): array
    {
        $raw = $theme['named'] ?? config('broadcast_digest.named', 3);

        if (is_array($raw)) {
            $min = max(1, (int) ($raw[0] ?? 3));
            $max = max($min, (int) ($raw[1] ?? $min));

            return [$min, $max];
        }

        $n = max(1, (int) $raw);

        return [$n, $n];
    }

    /**
     * @param  array{theme: array<string, string>, named: list<object>, total: int, venues: int}  $picked
     * @param  TelegramChatBroadcastItem|null  $item  запись, если у неё уже есть текст модели
     */
    private function buildCaption(
        TelegramChatBroadcast $broadcast,
        array $picked,
        Carbon $publishAt,
        ?TelegramChatBroadcastItem $item = null,
    ): string {
        $theme = $picked['theme'];
        // Срок в шапке — окно ЭТОЙ рубрики, а не общее, и считается тем же
        // методом, что окно отбора. Пока их было два, шапка «25 сентября –
        // 2 октября» обещала восемь дней там, где отбор смотрел пять.
        [$since, $to] = $this->themeWindow((array) $theme, $publishAt);
        // У окна «N дней вперёд» своего низа нет — им служит сам момент выхода.
        $from = $since ?? $publishAt->copy()->setTimezone(self::TZ);
        $forms = (array) ($theme['forms'] ?? []);

        // Город в шапке не пишем — канал городской, а площадки в строках
        // фактов и так его называют.
        $range = $from->month === $to->month
            ? sprintf('%d–%d %s', $from->day, $to->day, self::MONTHS[$to->month])
            : sprintf('%d %s – %d %s', $from->day, self::MONTHS[$from->month], $to->day, self::MONTHS[$to->month]);

        // «Спектакли НЕДЕЛИ» — про тему, и это верно. Но «Вечером недели» или
        // «Бесплатно недели» по-русски не говорят: у рубрик по поводу заголовок
        // свой, и задаётся он в конфиге.
        $headline = (string) ($theme['headline'] ?? ($theme['title'].' недели'));

        // Число — из той же выборки, на которую ведёт подвал. У рубрик без
        // фильтра ленты (тематических) его нет, и шапка остаётся как была.
        $feed = $this->feedSpec((array) $theme, $broadcast, $from, $to);
        $total = ($theme['count'] ?? true) ? $this->feedTotal($feed['count'] ?? null) : null;

        // Со счётом шапка — одно предложение, срок из неё уходит: «на выходных»
        // уже сказало когда. Без счёта — как было, с диапазоном дат.
        // Город в шапке ТОЛЬКО в форме со счётом. Прежнее решение «город не
        // пишем — канал городской» верно для шапки-заголовка: там он занимал
        // место, ничего не сообщая. В предложении «На выходных в Воронеже 136
        // событий» он держит фразу: без него выходит «На выходных 136
        // событий» — верно, но звучит как строка отчёта.
        $city = CityCase::prepositional($broadcast->chat?->city?->slug);

        if ($total !== null && $total > 0) {
            // Город по решению рубрики: «Бесплатно в эти дни в Воронеже»
            // спотыкается о два «в» подряд.
            $where = ! empty($theme['city_in_head']) && $city !== null ? ' в '.$city : '';

            // Тире: без него число прилипает — «Бесплатно в эти дни 26 событий».
            $head = trim(($theme['emoji'] ?? '').' <b>'
                .$this->escape($headline.$where.' — ')
                .$this->escape($this->plural($total, $forms[0] ?? 'событие', $forms[1] ?? null, $forms[2] ?? null))
                .'.</b>');
        } else {
            // у повода город в самом заголовке: «Хэллоуин в Воронеже: где отмечать»
            $headline = trim((string) preg_replace('/\s+(?=[:,.]|$)/u', '', str_replace('{city}', $city !== null ? 'в '.$city : '', $headline)));
            $head = trim(($theme['emoji'] ?? '').' <b>'.$this->escape($headline).'</b>')
                .(($theme['range_in_head'] ?? true) ? ' · '.$this->escape($range) : '');
        }

        // Подводку берём, только если она написана под этот состав: подпись собирают до того,
        // как из меты уберут текст под прежний состав (см. `withoutStaleDigestText`).
        $namedIds = array_map(fn ($r) => (int) $r->id, $picked['named']);
        $written = $item?->digestIntroFor($namedIds);
        $intro = config('broadcast_digest.intro', true) && ($theme['intro'] ?? true) ? $written : null;

        // Подводка — фраза своего события. Показана — событие стоит сразу под ней без строки,
        // выключена — её текст печатается фразой события на его месте.
        $leadId = $written !== null ? $item?->digestIntroEvent() : null;
        $leadOnTop = $intro !== null && $leadId !== null;
        $named = $picked['named'];
        if ($leadOnTop) {
            usort($named, fn ($a, $b) => ((int) $b->id === $leadId) <=> ((int) $a->id === $leadId));
        }
        $this->postOrder = array_map(fn ($r) => (int) $r->id, $named);

        // Форма даты решается ОДИН РАЗ на весь пост: вперемешку «сб 13:30» и
        // «сб 26 сентября, 18:00» читаются как сбой, а не как решение.
        // Нижняя граница — $from, а не $since: у недельного окна $since пуст, и
        // склейка тянула ПРОШЕДШИЕ сеансы («сб 12 сентября и сб 3 октября»).
        $sessions = $this->sessionsFor($picked['named'], $from, $to);
        $shortDates = $this->withinSixDays($picked['named'], $sessions, $publishAt)
            && $this->weekdaysAreDistinct($picked['named'], $sessions);
        // Значок у строки — только там, где строки РАЗНЫЕ по теме. В подборке
        // одной темы столбец выходит из одинаковых значков, а иногда и
        // противоречит рубрике: «🎵 Танцевальное шоу» в спектаклях.
        $emoji = ($theme['pick'] ?? 'interest') === 'interest'
            ? []
            : $this->emojiFor($picked['named'], $item, (string) ($theme['emoji'] ?? ''));

        $compact = ($theme['line'] ?? '') === 'compact';

        $lines = [];
        $rich = [];
        foreach ($named as $row) {
            $hook = match (true) {
                ($theme['hooks'] ?? true) === false, $leadOnTop && (int) $row->id === $leadId => '',
                (int) $row->id !== $leadId => $this->hook($row, $item, null, $compact),
                default => $this->hook($row, $item, $written, $compact),
            };

            if ($compact) {
                $lines[] = $this->compactLine($row, $shortDates, $sessions[(int) $row->id] ?? [], $emoji[(int) $row->id] ?? '', $item, (string) ($broadcast->chat?->city?->name ?? ''), $city);
                $rich[] = $hook === '' ? end($lines) : end($lines).' '.$this->escape($hook);

                continue;
            }

            $meta = array_values(array_filter([
                $this->whenLabel($row, $shortDates, $sessions[(int) $row->id] ?? []),
                $this->venueLabel($row),
                $this->priceLabel($row),
            ]));

            // НЕ $head: этим именем выше назван заголовок поста, и повторное
            // использование затирало его названием последнего события.
            // Пустой значок не оставляет пробела: строка начиналась бы с отступа.
            $mark = $emoji[(int) $row->id] ?? '';
            $titleLink = trim($mark.' <b>'
                .$this->link($this->eventUrl((int) $row->id, $item?->id), (string) $row->title).'</b>');
            // Без курсива: пять наклонных строк подряд читаются как сноски.
            $facts = $this->escape(implode(' · ', $meta));

            // Блок заканчивается человеческой фразой, а не ценой. Тот же приём,
            // что промпт канала называет «панч отдельной строкой», но в вёрстке.
            $lines[] = $titleLink."\n".$facts;
            $rich[] = $hook === ''
                ? $titleLink."\n".$facts
                : $titleLink."\n".$facts."\n".$this->escape($hook);
        }

        // Подвал БЕЗ числа — решение владельца, записанное в NEXT.md: у
        // категорийного лендинга нет недельного фильтра, он рисует всю будущую
        // афишу. Обещать «ещё 36 на этой неделе» и привести на страницу, где
        // события другие, — обмануть в мелочи, которую читатель проверит первым
        // же нажатием.
        $label = (string) ($theme['footer'] ?? 'Вся афиша '.($forms[2] ?? mb_strtolower((string) $theme['title'])));
        $link = $this->link(
            $this->landingUrl($broadcast, (string) $theme['slug'], $item?->id, $theme, $feed['link'] ?? null),
            $label,
        );

        // «Остальные N» — только когда N посчитан по той же выборке и правда
        // остались. Названные входят в total, поэтому вычитаем их.
        $rest = $total !== null ? $total - count($picked['named']) : 0;

        // Число БЕЗ существительного: рядом стоит подпись ссылки, и «остальные
        // 23 события — вся афиша событий» повторяет слово дважды в семи словах.
        $footer = match (true) {
            // «Что смотрят» ведёт не на страницу, а объясняет, откуда список
            isset($theme['footer_text']) => $this->escape((string) $theme['footer_text']),
            $rest > 0 => 'Остальные '.$rest.' — '.$this->link(
                $this->landingUrl($broadcast, (string) $theme['slug'], $item?->id, $theme, $feed['link'] ?? null),
                (string) ($theme['feed_label'] ?? mb_strtolower($label)),
            ),
            default => $link,
        };

        $top = array_values(array_filter([$head, $intro !== null ? $this->escape($intro) : null]));

        // ИЗЮМ СНИМАЕТСЯ ПО ОДНОЙ СТРОКЕ С КОНЦА, а не весь разом.
        //
        // Правило «целиком или никак» стояло ради того, чтобы пост не выходил
        // обрезанным на полуслове, и это верно: резать строку нельзя. Но
        // снятие ВСЕХ строк ради одной лишней — плата не за то: текст уже
        // написан и оплачен, а без него пост становится списком из базы.
        //
        // С конца, а не самую длинную: хвост подборки слабее начала, и дыра
        // в середине читается как сбой. Строка снимается целиком, так что
        // обрезанных на полуслове по-прежнему не бывает.
        $soft = (int) config('broadcast_digest.caption_soft_limit', 950);

        // СКОЛЬКО МЕСТА ОСТАЁТСЯ НА ФРАЗУ — считаем здесь и сохраняем, потому
        // что только здесь известна вся арифметика: шапка со счётом, подвал,
        // настоящие названия и строки фактов.
        //
        // Бюджет зависит от ЧИСЛА строк, а он у рубрик разный: замер
        // 25.09.2026 — при трёх названных на фразу остаётся около 200 знаков,
        // при пяти всего 91, а модель пишет в среднем 101. То есть на пяти
        // строках фразы начали бы сниматься с конца просто потому, что порог
        // был один на всех.
        // короткие строки идут сплошным списком: значок в начале и так их разделяет
        $list = fn (array $rows) => $compact ? [implode("\n", $rows)] : $rows;
        $bare = implode("\n\n", array_merge($top, $list($lines), [$footer]));
        $phrases = max(1, count($named) - ($leadOnTop ? 1 : 0));
        $this->hookBudget = max(
            self::HOOK_BUDGET_MIN,
            (int) floor(($soft - \App\Support\Telegram\CaptionLength::visible($bare)) / $phrases),
        );
        // короткой строке нужна короткая фраза, даже если место есть
        if (isset($theme['hook_max'])) {
            $this->hookBudget = max(self::HOOK_BUDGET_MIN, min($this->hookBudget, (int) $theme['hook_max']));
        }

        for ($keep = count($rich); $keep >= 0; $keep--) {
            $body = array_merge(
                array_slice($rich, 0, $keep),
                array_slice($lines, $keep),
            );
            $caption = implode("\n\n", array_merge($top, $list($body), [$footer]));

            if ($keep === 0 || \App\Support\Telegram\CaptionLength::visible($caption) <= $soft) {
                return $caption;
            }
        }

        return implode("\n\n", array_merge($top, $list($lines), [$footer]));
    }

    /**
     * Строка в одну фразу: «🎲 Название — сб 31 октября, 18:00 · 1500 ₽.» Площадку, которая уже
     * есть в названии, второй раз не пишем.
     *
     * @param  list<Carbon>  $times
     */
    private function compactLine(object $row, bool $shortDates, array $times, string $mark, ?TelegramChatBroadcastItem $item, string $city, ?string $cityIn = null): string
    {
        $venue = $this->venueLabel($row);
        if ($venue === '' && trim((string) ($row->address ?? '')) !== '') {
            $venue = EventCaptionBuilder::placeFromAddress((string) $row->address, $city);
        }
        // имя площадки из группы ВК «Настольные игры | ПараDice | Воронеж»: в названии события бывает вторая часть
        $parts = array_filter(array_merge([$venue], preg_split('/\s+[|\/]\s+/u', (string) ($row->venue_name ?? '')) ?: []),
            fn ($p) => mb_strlen(trim((string) $p)) >= 3 && mb_strtolower(trim((string) $p)) !== mb_strtolower(trim($city)));
        foreach ($parts as $part) {
            if (mb_stripos((string) $row->title, trim((string) $part)) !== false) {
                $venue = '';
                break;
            }
        }
        // посреди строки вид заведения со строчной: «рестобар «Литера»», «рок-бар The Last of Vavilone»
        $venue = (string) preg_replace_callback('/^[А-ЯЁ][а-яё]+(?:-[а-яё]+)?(?=\s+(?:«|\p{Latin}))/u', fn ($m) => mb_strtolower($m[0]), $venue);

        $facts = implode(' · ', array_filter([
            implode(', ', array_filter([$this->whenLabel($row, $shortDates, $times), $venue])),
            $this->priceLabel($row),
        ]));

        // «Готический Halloween в Воронеже» под заголовком «Хэллоуин в Воронеже»: город уже назван
        $title = $cityIn === null ? (string) $row->title
            : (string) preg_replace('/\s+в\s+'.preg_quote($cityIn, '/').'$/u', '', (string) $row->title);

        return trim($mark.' '.$this->link($this->eventUrl((int) $row->id, $item?->id), $this->typography(CalmCaps::title($title))))
            .' — '.$this->escape($facts).'.';
    }

    /**
     * Все строки в ближайшие шесть дней после выхода: дальше «сб» без числа читают как ближайшую
     * субботу, а событие через неделю — уже другая.
     *
     * @param  list<object>  $named
     * @param  array<int, list<Carbon>>  $sessions
     */
    private function withinSixDays(array $named, array $sessions, Carbon $publishAt): bool
    {
        $start = $publishAt->copy()->setTimezone(self::TZ)->startOfDay();

        foreach ($named as $row) {
            $times = $sessions[(int) $row->id] ?? [];
            if ($times === [] || count($times) > self::MERGE_MAX) {
                $times = [Carbon::parse($row->start_time)->setTimezone(self::TZ)];
            }
            foreach ($times as $at) {
                if ($start->diffInDays($at->copy()->startOfDay()) > 6) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Строка-изюм про событие: чем оно цепляет.
     *
     * Четыре источника по старшинству — блок ИЗЮМ в config/broadcast_digest.php.
     * Сами ничего не сочиняем: подборка и так рискует звучать списком из базы,
     * а выдуманная фраза к этому добавит вранья.
     *
     * @param  string|null  $written  текст модели вместо строки события из меты
     */
    private function hook(object $row, ?TelegramChatBroadcastItem $item = null, ?string $written = null, bool $writtenOnly = false): string
    {
        $limit = (int) config('broadcast_digest.named_line_chars', 130);

        // 1. Строка модели написана ПОД ЭТУ СТРОКУ — целиком, а не первой
        // фразой из анонса. Резать её по первому предложению нельзя: панч у
        // такой строки обычно во второй половине.
        $written ??= $item?->digestHook((int) $row->id);
        if ($written !== null) {
            return $this->typography($this->trimToWord((string) preg_replace('/\s+/u', ' ', $written), (int) round($limit * 1.3)));
        }
        // короткой строке фраза из анонса или описания велика: лучше без неё
        if ($writtenOnly) {
            return '';
        }

        // 2. Наш собственный анонс события — он уже в голосе канала. Если у
        // самого события его нет, берём у близнеца по группе повторов: один и
        // тот же концерт приезжает из разных источников разными строками, и
        // анонс, написанный одной из них, годится для всех.
        $text = trim((string) ($row->tg_description ?? ''));
        if ($text === '' && $row->event_group_id !== null) {
            $text = $this->twinAnnounce((int) $row->event_group_id, (int) $row->id);
        }
        // Канал говорит на «вы». Анонсы на «ты» остались от прежних прогонов
        // («Хочешь фото — приходи за два часа»), и в подборке, где рядом стоят
        // три строки, такой сбой голоса заметнее всего.
        if ($text !== '' && $this->speaksTy($text)) {
            $text = '';
        }

        // 3. Описание источника — но только если в нём есть что сказать.
        // Допуск тот же, что у строки модели: предложение источника длиннее
        // ровно потому, что писали его не под эту строку.
        if ($text === '') {
            $text = $this->liveSentence(
                (string) ($row->description ?? ''),
                (int) round($limit * 1.3),
                (string) $row->title,
            );
        }

        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace('/\s+/u', ' ', $text);

        // Первое законченное предложение, если оно не слишком длинное.
        $sentences = $this->sentences($text);
        if ($sentences !== [] && mb_strlen($sentences[0]) >= 40 && mb_strlen($sentences[0]) <= $limit) {
            return $this->typography($sentences[0]);
        }

        return $this->typography($this->trimToWord($text, $limit));
    }

    /**
     * Кавычки — одни на весь пост.
     *
     * Источники присылают лапки, наши тексты — ёлочки, и в одном посте они
     * стояли рядом. Мелочь ровно того сорта, по которой видно, что текст
     * собран машиной из чужих кусков.
     */
    private function typography(string $s): string
    {
        $s = (string) preg_replace('/"([^"]{1,80})"/u', '«$1»', $s);

        return (string) preg_replace('/\s+([,.!?;:])/u', '$1', $s);
    }

    /** Анонс близнеца: тот же концерт из другого источника, наш текст уже написан. */
    private function twinAnnounce(int $groupId, int $exceptEventId): string
    {
        return trim((string) DB::table('events')
            ->where('event_group_id', $groupId)
            ->where('id', '<>', $exceptEventId)
            ->whereNull('deleted_at')
            ->whereNotNull('tg_description')
            ->where('tg_description', '<>', '')
            ->orderByDesc('updated_at')
            ->value('tg_description'));
    }

    /**
     * Первое предложение описания, в котором есть ЧТО-ТО КРОМЕ вежливости.
     *
     * Список зачинов и причина — у `dead_openings` в config/broadcast_digest.php.
     * Не нашлось живого — строки не будет вовсе: пустое место честнее чужой
     * вежливости.
     */
    private function liveSentence(string $description, int $limit, string $title = ''): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $description));
        if ($text === '') {
            return '';
        }

        $dead = (array) config('broadcast_digest.dead_openings', []);
        $sentences = $this->sentences($text);

        $live = [];
        foreach (array_slice($sentences, 0, 5) as $sentence) {
            $sentence = trim((string) $sentence);
            if (mb_strlen($sentence) < 40) {
                continue;
            }

            $low = mb_strtolower($sentence);
            $isDead = false;
            foreach ($dead as $word) {
                if (str_contains($low, (string) $word)) {
                    $isDead = true;

                    break;
                }
            }

            // Цена в строке — дубль строки фактов, которая стоит прямо над ней.
            if ($isDead || preg_match('/\d+\s*(₽|руб)/u', $sentence)) {
                continue;
            }

            if (! $this->retellsTitle($sentence, $title)) {
                $live[] = $sentence;
            }
        }

        if ($live === []) {
            return '';
        }

        // Первое живое — оно про само событие. Дальше по тексту предложения
        // уходят в логистику («билеты возвращаются в кассе»), и взятое оттуда
        // читается как ответ не на тот вопрос.
        if (mb_strlen($live[0]) <= $limit) {
            return $live[0];
        }

        // Не влезло целиком — обрезаем по границе оборота. Длинное предложение
        // источника почти всегда составное («…как вынужденная замена: ранее на
        // этот вечер был запланирован спектакль „Отцы и сыновья“, который не
        // сможет состояться по техническим причинам»), и первая его половина —
        // законченная мысль, а не обрубок.
        $clause = $this->clauseCut($live[0], $limit);
        if ($clause !== '') {
            return $clause;
        }

        // Совсем не режется — ищем среди следующих то, что влезет целиком.
        foreach (array_slice($live, 1) as $sentence) {
            if (mb_strlen($sentence) <= $limit) {
                return $sentence;
            }
        }

        return $live[0];
    }

    /** Обращение на «ты» — чужой голос: канал городской афиши говорит на «вы». */
    private function speaksTy(string $s): bool
    {
        return (bool) preg_match(
            '/\b(ты|тебе|тебя|тобой|твой|твоя|твои|твоего|приходи|хочешь|успей|загляни|бери|лови)\b/ui',
            $s,
        );
    }

    /**
     * Строка пересказывает заголовок, который стоит прямо над ней.
     *
     * «Приглашаем вас на спектакль „Золушка“, который пройдёт в ТЮЗе» под
     * названием «Спектакль „Золушка“» — читатель читает одно и то же дважды.
     * По замеру 2026-09-15 так устроена треть строк, взятых из описаний.
     */
    private function retellsTitle(string $sentence, string $title): bool
    {
        if (trim($title) === '') {
            return false;
        }

        $stems = static function (string $s): array {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_unique(array_map(
                static fn ($w) => mb_substr((string) $w, 0, 5),
                array_filter($words, static fn ($w) => mb_strlen((string) $w) >= 4),
            )));
        };

        $own = $stems($sentence);
        if ($own === []) {
            return false;
        }

        $fresh = array_diff($own, $stems($title));

        return count($fresh) * 2 < count($own);
    }

    /**
     * Разбить текст на предложения, не спотыкаясь об инициалы и сокращения.
     *
     * «Опера С. Прокофьева», «театр кукол им. Вольховского», «по пьесе
     * В. Розова» — точка там стоит, а предложение не кончилось. Наивное
     * деление по точке давало в посте обрубки вида «Опера С.» — 5% строк.
     *
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }

        $guard = (string) preg_replace('/(\b\p{Lu})\.(\s)/u', '$1<DOT>$2', $text);
        $guard = (string) preg_replace('/\b(им|ул|пр|г|гг|стр|д|корп|т|тт|св|пос|обл|р|c|см|др|проч)\.(\s)/ui', '$1<DOT>$2', $guard);

        $parts = preg_split('/(?<=[.!?])\s+/u', $guard) ?: [];

        $out = [];
        foreach ($parts as $part) {
            $part = trim(str_replace('<DOT>', '.', (string) $part));
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /** Половина составного предложения — по последней запятой или двоеточию в пределах лимита. */
    private function clauseCut(string $sentence, int $limit): string
    {
        $cut = mb_substr($sentence, 0, $limit);

        $at = 0;
        foreach ([',', ';', ':', '—', '–'] as $mark) {
            $pos = mb_strrpos($cut, $mark);
            if ($pos !== false && $pos > $at) {
                $at = $pos;
            }
        }

        if ($at < 60) {
            return '';
        }

        return rtrim(mb_substr($sentence, 0, $at), ' ,;:—–-').'.';
    }

    /**
     * Режем по слову, а не по символу: «спекта…» читается как сбой.
     *
     * И не по СЛУЖЕБНОМУ слову: «…„Разлетайтесь мыши" и…» — реальная строка из
     * живого поста. Висящий союз читается как обрыв связи, а не как
     * продолжение; отбрасываем его вместе с многоточием.
     */
    private function trimToWord(string $text, int $limit): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $at = mb_strrpos($cut, ' ');
        $cut = trim($at === false ? $cut : mb_substr($cut, 0, $at));

        // Хвостовые союзы и предлоги — по одному, пока они там есть: после
        // «на» может остаться «и», и обрывать на нём так же нехорошо.
        $tail = ['и', 'а', 'но', 'да', 'или', 'с', 'со', 'в', 'во', 'на', 'от', 'до',
            'по', 'за', 'из', 'у', 'к', 'о', 'об', 'для', 'при', 'про', 'что', 'как'];

        while (true) {
            $cut = rtrim($cut, ' ,;:—–-');
            $lastSpace = mb_strrpos($cut, ' ');
            if ($lastSpace === false) {
                break;
            }
            $lastWord = mb_strtolower(trim(mb_substr($cut, $lastSpace + 1), " ,;:—–-«»\"'"));
            if (! in_array($lastWord, $tail, true)) {
                break;
            }
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, ' ,;:—–-').'…';
    }

    /** Рубрика по своему расписанию (`auto` false): не набралась — другой рубрикой её не подменяют. */
    public static function ownSchedule(?string $slug): bool
    {
        return $slug !== null && collect((array) config('broadcast_digest.themes', []))
            ->contains(fn ($t) => ($t['slug'] ?? null) === $slug && ($t['auto'] ?? true) === false);
    }

    /**
     * Ближайший день повода, см. OccasionCalendar::occurrence.
     *
     * @param  array<string, mixed>  $theme
     */
    public static function occasionDate(array $theme, Carbon $at): ?Carbon
    {
        return OccasionCalendar::occurrence($theme, $at)['day'] ?? null;
    }

    /**
     * Окно рубрики — один метод на отбор и на шапку: пока их было два, шапка
     * обещала восемь дней там, где отбор смотрел пять. У «N дней вперёд» низа
     * нет (его держит [[PostTiming]]), у «выходных» он обязателен — иначе в
     * подборку попадёт вечер пятницы.
     *
     * @param  array<string, mixed>  $theme
     * @return array{0: Carbon|null, 1: Carbon}
     */
    private function themeWindow(array $theme, Carbon $publishAt): array
    {
        $at = $publishAt->copy()->setTimezone(self::TZ);

        if (($theme['window'] ?? '') === 'weekend') {
            // Той же формулой, что и лента: подвал такой рубрики ведёт на
            // ?when=weekend, и разойтись им нельзя. См. [[WeekendWindow]].
            return WeekendWindow::for($at);
        }

        if (isset($theme['occasion'])) {
            // даты в списке кончились: окна нет, подборка не соберётся
            return OccasionCalendar::window($theme, $at) ?? [$at->copy(), $at->copy()];
        }

        $skip = (int) ($theme['window_from_days'] ?? 0);
        if ($skip > 0) {
            $until = $at->copy()->addDays((int) ($theme['window_days'] ?? $skip))->endOfDay();
            // «На этой неделе» не заходит в следующую, на какой бы день недели её ни поставили
            if (! empty($theme['this_week'])) {
                $until = $until->min($at->copy()->endOfWeek(Carbon::SUNDAY));
            }

            return [$at->copy()->addDays($skip)->startOfDay(), $until];
        }

        return [null, $at->copy()->addDays(
            (int) ($theme['window_days'] ?? config('broadcast_digest.window_days', 7)),
        )];
    }

    /**
     * Значок для каждой строки. Подавление «тема названа словом» выключено:
     * в подборке значок — столбец, и дыра в нём читается как сбой вёрстки
     * (замер: с подавлением пусто у 167 событий из 465, без него у 12).
     * Значок из digest_meta.emoji (его подбирает модель короткому списку) перекрывает вычисленный,
     * кроме значка самой рубрики: он уже стоит в заголовке.
     *
     * @param  list<object>  $named
     * @return array<int, string>
     */
    private function emojiFor(array $named, ?TelegramChatBroadcastItem $item, string $themeEmoji = ''): array
    {
        if ($named === []) {
            return [];
        }

        $ids = array_map(static fn ($r) => (int) $r->id, $named);

        $slugs = [];
        foreach (DB::table('event_interest as ei')
            ->join('interests as i', 'i.id', '=', 'ei.interest_id')
            ->whereIn('ei.event_id', $ids)
            ->orderBy('ei.rank')
            ->get(['ei.event_id', 'i.slug']) as $row) {
            $slugs[(int) $row->event_id][] = (string) $row->slug;
        }

        $manual = (array) (($item->digest_meta ?? [])['emoji'] ?? []);

        $out = [];
        foreach ($named as $row) {
            $id = (int) $row->id;
            $byHand = trim((string) ($manual[(string) $id] ?? ''));

            $out[$id] = $byHand !== '' && $byHand !== trim($themeEmoji) ? $byHand : KindEmoji::for([
                'title' => $row->title,
                'interest_slugs' => $slugs[$id] ?? [],
                'content_kind' => $row->content_kind ?? null,
            ], suppressWhenTitleSaysIt: false);
        }

        return $out;
    }

    /**
     * Строка времени: «сб 13:30» или «сб 26 сентября, 18:00».
     *
     * Короткая форма экономит двенадцать знаков на строке — в посте из пяти
     * строк это место под целое шестое событие.
     */
    /**
     * Сеансы названных событий внутри окна рубрики.
     *
     * Берём ПОСЛЕ отбора и только для названных: collapseRepeats сносит
     * близнецов из пула до сборки, и лезть туда — значит трогать отбор,
     * который работает.
     *
     * @param  list<object>  $named
     * @return array<int, list<Carbon>>
     */
    private function sessionsFor(array $named, ?Carbon $since, Carbon $until): array
    {
        $groups = [];
        foreach ($named as $row) {
            if ($row->event_group_id !== null) {
                $groups[(int) $row->event_group_id][] = (int) $row->id;
            }
        }

        if ($groups === []) {
            return [];
        }

        $rows = DB::table('events')
            ->whereIn('event_group_id', array_keys($groups))
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('start_time', '<=', $until)
            ->when($since !== null, fn ($q) => $q->where('start_time', '>=', $since))
            // запись без времени — не сеанс: в start_time у неё полночь, и вышло бы «00:00 и 19:00»
            ->where('time_precision', '<>', 'date')
            ->orderBy('start_time')
            ->get(['event_group_id', 'start_time']);

        $byGroup = [];
        foreach ($rows as $r) {
            $byGroup[(int) $r->event_group_id][] = Carbon::parse($r->start_time)->setTimezone(self::TZ);
        }

        $out = [];
        foreach ($groups as $groupId => $eventIds) {
            foreach ($eventIds as $eventId) {
                $out[$eventId] = $byGroup[$groupId] ?? [];
            }
        }

        return $out;
    }

    private function whenLabel(object $row, bool $short, array $times = []): string
    {
        $at = Carbon::parse($row->start_time)->setTimezone(self::TZ);

        $day = fn (Carbon $t) => $short
            ? self::WEEKDAYS[(int) $t->isoWeekday()]
            : self::WEEKDAYS[(int) $t->isoWeekday()].' '.$t->day.' '.self::MONTHS[$t->month];

        // У события без времени в start_time стоит полночь, и «сб 00:00» —
        // это не факт, а артефакт: источник назвал только день.
        if ((string) ($row->time_precision ?? 'datetime') === 'date') {
            return $day($at);
        }

        if (($merged = $this->mergedWhen($times, $short, $day)) !== null) {
            return $merged;
        }

        return $short
            ? $day($at).' '.$at->format('H:i')
            : $day($at).', '.$at->format('H:i');
    }

    /**
     * Несколько сеансов одной строкой: «сб и вс с 12:00», «вс 11:30 и 14:30».
     *
     * Только две формы, у которых склейка честна. Смешанный случай (разные дни
     * И разное время) короткой формы не имеет — там печатаем один сеанс, как
     * печатали всегда.
     *
     * @param  list<Carbon>  $times
     */
    private function mergedWhen(array $times, bool $short, callable $day): ?string
    {
        if (count($times) < 2 || count($times) > self::MERGE_MAX) {
            return null;
        }

        $hours = array_values(array_unique(array_map(fn (Carbon $t) => $t->format('H:i'), $times)));
        $dates = array_values(array_unique(array_map(fn (Carbon $t) => $t->toDateString(), $times)));

        // Одно время, разные дни: «сб и вс с 12:00».
        if (count($hours) === 1 && count($dates) === count($times)) {
            $days = array_map($day, $times);
            $last = array_pop($days);

            return ($days === [] ? $last : implode(', ', $days).' и '.$last)
                .($short ? ' с ' : ', с ').$hours[0];
        }

        // Один день, разное время: «вс 11:30 и 14:30».
        if (count($dates) === 1) {
            $last = array_pop($hours);

            return $day($times[0]).($short ? ' ' : ', ')
                .($hours === [] ? $last : implode(', ', $hours).' и '.$last);
        }

        return null;
    }

    /**
     * Можно ли печатать день недели без числа.
     *
     * «сб» однозначно ровно до тех пор, пока суббота в составе одна. Окно
     * рубрики бывает и восьмидневным (window_days=7 считается от МОМЕНТА
     * публикации, а не от полуночи), и тогда в составе встретятся две разные
     * субботы — читатель придёт не в тот день.
     *
     * @param  list<object>  $named
     */
    private function weekdaysAreDistinct(array $named, array $sessions = []): bool
    {
        $seen = [];

        foreach ($named as $row) {
            // Склеенная строка занимает все свои дни, а не только первый.
            $times = $sessions[(int) $row->id] ?? [];
            if ($times === [] || count($times) > self::MERGE_MAX) {
                $times = [Carbon::parse($row->start_time)->setTimezone(self::TZ)];
            }

            foreach ($times as $at) {
                $weekday = (int) $at->isoWeekday();
                $date = $at->toDateString();

                // Два события одного дня — не помеха: это буквально один день.
                if (isset($seen[$weekday]) && $seen[$weekday] !== $date) {
                    return false;
                }

                $seen[$weekday] = $date;
            }
        }

        return true;
    }

    private function priceLabel(object $row): string
    {
        // «Свободно», а не «бесплатно»: короче на два знака и не спорит с
        // «свободно, по регистрации», где «бесплатно по регистрации» звучит
        // как оговорка к бесплатности.
        if ($row->price_status === 'free') {
            return $this->needsSignup($row) ? 'свободно, по регистрации' : 'свободно';
        }

        // Пожертвование раньше давало ПУСТУЮ строку цены: статус не 'free', а
        // сумм у таких событий нет. При этом в пул рубрики «Бесплатно» они
        // берутся наравне с free — и выходили в пост без единого слова о цене.
        if ($row->price_status === 'donation') {
            return 'свободный взнос';
        }

        $min = $row->price_min !== null ? (int) $row->price_min : null;
        $max = $row->price_max !== null ? (int) $row->price_max : null;

        if ($min === null && $max === null) {
            return '';
        }

        // Неразрывный пробел перед знаком: на узком экране строка фактов
        // переносится, и «4900» уезжало на одну строку, а «₽» на другую.
        $amount = (string) ($min ?? $max);

        // «от 0 ₽» правда, но читается как сбой: у такой цены низ не значит
        // ничего, а верх значит. Поймано в живом посте 24.09.2026.
        if ($min === 0 && $max !== null && $max > 0) {
            return 'до '.$max."\u{00A0}₽";
        }

        return $min !== null && $max !== null && $min !== $max
            ? 'от '.$min."\u{00A0}₽"
            : $amount."\u{00A0}₽";
    }

    /**
     * Нужна ли на бесплатное событие регистрация.
     *
     * Отдельного поля под это нет — слово живёт только в тексте цены. Замер по
     * живой базе: 65 событий упоминают регистрацию, и ВСЕ 65 — в смысле «она
     * нужна»; ни одного «без регистрации» или «регистрация закрыта». Отрицания
     * всё равно отсекаем: данные приходят каждый час, и первое же такое
     * событие иначе позвало бы людей регистрироваться на пустом месте.
     */
    private function needsSignup(object $row): bool
    {
        $text = mb_strtolower(trim((string) ($row->price_text ?? '')));

        if ($text === '' || ! preg_match('/регистрац/u', $text)) {
            return false;
        }

        return ! preg_match('/(без\s+регистрац|не\s+нужн\w*\s+регистрац|не\s+требу\w*\s+регистрац|регистрац\w*\s+(не|закрыт|заверш))/u', $text);
    }

    /**
     * Имя площадки без мусора источника — общим правилом, см. [[VenueName]].
     *
     * Правило переехало в Support, когда имя площадки начал печатать и пост
     * ленты: два экземпляра одной чистки разъехались бы в первый же месяц.
     */
    private function venueLabel(object $row): string
    {
        return VenueName::label($row->venue_name ?? null);
    }

    /**
     * @param  int|null  $itemId  номер записи в метке `utm_content`: по нему
     *                            Метрика отличает переходы ЭТОЙ подборки от
     *                            прошлой — иначе рубрика измерима только целиком
     */
    /**
     * @param  array<string, mixed>|null  $theme  рубрика, если у неё своя посадка
     */
    /**
     * Фильтр ленты рубрики: ОДНО описание, две стороны.
     *
     * `count` — чем считаем: фильтр рубрики плюс город и ГРАНИЦЫ ЕЁ ОКНА.
     * `link` — что пишем в адрес: тот же фильтр как объявлен, плюс город.
     *
     * Почему они не одинаковы и почему это не расхождение. У «На выходных»
     * объявлено `when=weekend` — лента развернёт его САМА, той же формулой
     * ([[WeekendWindow]]), которой посчитано окно рубрики. Писать в адрес ещё
     * и даты значило бы удлинить ссылку вчетверо, не добавив ни одного факта.
     * У остальных рубрик `when` нет, и тогда границы идут и в счёт, и в адрес.
     *
     * Рубрика без `feed` (тематические, подвал ведёт на страницу категории)
     * возвращает null — и числа у такой не будет.
     *
     * @param  array<string, mixed>  $theme
     * @return array{count: array<string, mixed>, link: array<string, mixed>}|null
     */
    private function feedSpec(array $theme, TelegramChatBroadcast $broadcast, Carbon $from, Carbon $to): ?array
    {
        $feed = $theme['feed'] ?? null;
        if (! is_array($feed)) {
            return null;
        }

        $cityId = $broadcast->chat?->city_id;
        if ($cityId === null) {
            return null;
        }

        // Считаем ВСЕГДА по границам окна рубрики, а не по «сегодня». Пост
        // собирается заранее — за сутки до слота, а то и раньше: взяв
        // now(), подборка на выходные, собранная в четверг, посчитала бы
        // прошедшие выходные, а в шапке стояли бы будущие.
        $count = Arr::except($feed, ['when']) + [
            'city_id' => (int) $cityId,
            'date_from' => $from->copy()->setTimezone(self::TZ)->toDateTimeString(),
            'date_to' => $to->copy()->setTimezone(self::TZ)->toDateTimeString(),
        ];

        $link = isset($feed['when'])
            ? $feed
            : $feed + [
                'date_from' => $count['date_from'],
                'date_to' => $count['date_to'],
            ];

        return ['count' => $count, 'link' => $link];
    }

    /**
     * Сколько событий покажет страница, на которую ведёт подвал.
     *
     * Именно та же выборка, что у ленты, — иначе пост обещает одно, а читатель
     * по нажатию видит другое. Замер 25.09.2026 на одних выходных: сырой
     * запрос к базе 150, лента 134. Считать своё нельзя.
     *
     * @param  array<string, mixed>|null  $filters
     */
    private function feedTotal(?array $filters): ?int
    {
        if ($filters === null) {
            return null;
        }

        try {
            return (int) $this->events->listWeb($filters, 1)['page']->total();
        } catch (\Throwable $e) {
            // Число — украшение, а пост — нет. Лента упала: выходим без счёта,
            // как выходили всегда.
            Log::warning('broadcast.digest.feed_total_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function landingUrl(TelegramChatBroadcast $broadcast, string $slug, ?int $itemId = null, ?array $theme = null, ?array $linkFilters = null): string
    {
        $base = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');
        $citySlug = $broadcast->chat?->city?->slug ?? '';
        $utm = (array) config('broadcast_digest.utm', []);

        // ЦЕНОВОЙ И ВРЕМЕННОЙ РУБРИКЕ ЛЕНДИНГА НЕТ. На сайте адреса вида
        // /afisha/{city}/{slug} заведены только у категорий (реестр —
        // landingCategories.ts) и у двух временных срезов. Отправить подвал на
        // /afisha/voronezh/besplatno значило бы привести читателя на 404.
        //
        // Зато лента умеет ровно эти фильтры: ?free=1, ?price_max=, ?tod=.
        // Ведём туда — читатель попадает на тот же отбор, что в посте.
        // Адрес собирается из ТОГО ЖЕ фильтра, по которому посчитано число в
        // посте. Готовой строки-адреса у рубрики больше нет намеренно: пока
        // они лежали порознь, число и страница могли разъехаться молча.
        $query = $linkFilters !== null
            ? http_build_query($linkFilters + ['city' => $citySlug])
            : '';

        $url = match (true) {
            // у праздника своя страница: программа по дням вместо ленты поиска
            isset($theme['page']) && $citySlug !== '' => $base.'/afisha/'.$citySlug.'/'.$theme['page'],
            $query !== '' => $base.'/events?'.$query,
            $citySlug !== '' => $base.'/afisha/'.$citySlug.'/'.$slug,
            default => $base.'/events',
        };

        // Подвал ведёт на лендинг и метку несёт с самого начала — через общий
        // помощник, чтобы она не разошлась с метками на строках поста.
        // Разделитель по месту: у ленты с фильтром вопрос в адресе уже стоит,
        // и второй превратил бы ссылку в «?free=1?utm_source=».
        $sep = str_contains($url, '?') ? '&' : '?';

        return $itemId === null
            ? $url.$sep.'utm_source='.($utm['source'] ?? 'tg').'&utm_medium='.($utm['medium'] ?? 'digest')
            : PostLink::utm($url, (string) ($utm['medium'] ?? 'digest'), $itemId);
    }

    /** Ссылка на карточку названного события — с меткой поста, см. [[PostLink]]. */
    private function eventUrl(int $id, ?int $itemId = null): string
    {
        $base = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');

        return PostLink::utm($base.'/events/'.$id, PostLink::MEDIUM_DIGEST, $itemId);
    }

    private function link(string $href, string $label): string
    {
        return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            .$this->escape($label).'</a>';
    }

    /** Заголовки приходят из парсеров — это чужой текст внутри нашей разметки. */
    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Ключ заголовка для сравнения: регистр и пунктуация значения не имеют. */
    private function titleKey(string $title): string
    {
        $norm = mb_strtolower(trim($title));
        $norm = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $norm);

        return trim((string) preg_replace('/\s+/u', ' ', $norm));
    }

    private function plural(int $n, string $one, ?string $few = null, ?string $many = null): string
    {
        if ($one === '') {
            return (string) $n;
        }
        if ($few === null || $many === null) {
            return $n.' '.$one;
        }

        $mod10 = $n % 10;
        $mod100 = $n % 100;

        $form = match (true) {
            $mod10 === 1 && $mod100 !== 11 => $one,
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => $few,
            default => $many,
        };

        return $n.' '.$form;
    }
}
