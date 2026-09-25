<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Support\Telegram\VenueName;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
     * Собрать подборку для канала на момент публикации.
     *
     * @return array{theme: array<string, string>, caption: string, event_ids: list<int>, total: int, venues: int}|null
     *                                                                                                                  null — ни одна тема не набрала состава; решать, что делать дальше, вызывающему
     */
    public function compose(
        TelegramChatBroadcast $broadcast,
        Carbon $publishAt,
        ?TelegramChatBroadcastItem $forItem = null,
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

        foreach ((array) config('broadcast_digest.themes', []) as $theme) {
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

        return [
            'theme' => $best['theme'],
            'theme_slug' => (string) $best['theme']['slug'],
            'caption' => $this->buildCaption($broadcast, $best, $publishAt, $forItem),
            'event_ids' => array_map(fn ($e) => (int) $e->id, $best['named']),
            'total' => $best['total'],
            'venues' => $best['venues'],
        ];
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

        return [
            'theme' => $picked['theme'],
            'theme_slug' => $slug,
            'caption' => $this->buildCaption($broadcast, $picked, $publishAt, $item),
            'event_ids' => array_map(fn ($e) => (int) $e->id, $named),
            'total' => $picked['total'],
            'venues' => $picked['venues'],
        ];
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
            ->where(fn ($q) => PostTiming::applyFits($q, $publishAt, 0, 'e'))
            ->get([
                'e.id', 'e.title', 'e.start_time', 'e.event_group_id', 'e.venue_id',
                'e.description', 'e.tg_description', 'e.price_min', 'e.price_max', 'e.price_status',
                'v.name as venue_name',
            ])
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

        if ($total < (int) config('broadcast_digest.min_events', 5)
            || $venues < (int) config('broadcast_digest.min_venues', 3)) {
            return null;
        }

        return [
            'theme' => $theme,
            'named' => $this->pickNamed($rows, self::namedRange($theme)),
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
        if ($slug === null || ! $cityId) {
            return null;
        }

        $theme = collect((array) config('broadcast_digest.themes', []))
            ->first(fn ($t) => (string) ($t['slug'] ?? '') === $slug);
        if ($theme === null) {
            return null;
        }

        // Состав — теми же свежими фактами, что увидит подпись: у события могли
        // поменять площадку или день, и пометки обязаны считаться от того, что
        // выйдет в канал, а не от того, что лежало в связи на момент сборки.
        $named = $this->namedFromLinks($item, $publishAt);
        $taken = array_map(static fn ($e) => (int) $e->id, $named);

        $pool = $this->poolForTheme($broadcast, (int) $cityId, (array) $theme, $publishAt, $item->id);
        $rows = $pool['rows'] ?? collect();

        $minDescription = (int) config('broadcast_digest.min_description', 120);

        // Площадки и дни занятых строк — с НОМЕРОМ строки: «та же площадка, что
        // во второй» полезнее, чем «площадка занята».
        // Заменяемая строка в споре не участвует: она уходит. Без этого самые
        // естественные замены — другой спектакль того же театра вместо этого —
        // помечались ложным «та же площадка, что в строке 2» при замене как раз
        // второй строки и утопали вниз списка.
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

        $out = [];
        foreach ($rows as $row) {
            if (in_array((int) $row->id, $taken, true)) {
                continue;
            }

            $venue = $row->venue_id !== null ? (int) $row->venue_id : null;
            $day = Carbon::parse($row->start_time)->setTimezone(self::TZ)->toDateString();
            $length = mb_strlen(trim((string) $row->description));

            $notes = [];
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
            } elseif ($length < $minDescription) {
                $notes[] = 'короткая карточка — строка выйдет сухой';
            }

            $out[] = [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'start_time' => $row->start_time ? Carbon::parse($row->start_time)->toIso8601String() : null,
                'venue' => $row->venue_name,
                'venue_id' => $venue,
                'description_length' => $length,
                'has_own_text' => trim((string) $row->tg_description) !== '',
                'clash' => $venue !== null && isset($venueAt[$venue]) || isset($dayAt[$day]) || $venue === null,
                'notes' => $notes,
            ];
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
        $withShown = $this->poolForTheme($broadcast, (int) $cityId, (array) $theme, $publishAt, $item->id, true);
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
    ): ?array {
        // РУБРИКА ОТБИРАЕТ НЕ ТОЛЬКО ПО ТЕМЕ. «Спектакли» — это интерес, а
        // «Бесплатно» и «Дешевле 500» — цена, «Вечером» — час начала. Признак
        // разный, всё остальное (срок, отсечки, схлопывание повторов)
        // одинаковое, поэтому развилка живёт здесь, а не в отдельном пуле.
        $pick = (string) ($theme['pick'] ?? 'interest');

        $ids = [];
        if ($pick === 'interest') {
            $ids = $this->interestTree((string) $theme['interest']);
            if ($ids === []) {
                return null;
            }
        }

        // Окно у рубрики может быть своё. Бесплатное объявляют поздно: замер
        // 25.09.2026 — 35 событий в ближайшую неделю и 4 в следующую, поэтому
        // недельное окно для него почти пустое, а трёхдневное полное.
        $until = $publishAt->copy()->addDays(
            (int) ($theme['window_days'] ?? config('broadcast_digest.window_days', 7)),
        );

        $rows = DB::table('events as e')
            ->when($pick === 'interest', fn ($q) => $q->join('event_interest as ei', 'ei.event_id', '=', 'e.id'))
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->join('communities as c', 'c.id', '=', 'e.community_id')
            ->whereNull('e.deleted_at')
            ->where('e.status', 'active')
            ->where('c.city_id', $cityId)
            ->where('e.start_time', '<=', $until)
            // Срок — общим правилом ([[PostTiming]]), а не «начнётся позже
            // поста»: у многодневки успеть надо к ЗАКРЫТИЮ. Из-за своей копии
            // рубрика «Выставки недели» по построению не могла назвать
            // выставку, открывшуюся на прошлой неделе и висящую месяц, — то
            // есть ровно ту, ради которой тема и заведена.
            ->where(fn ($q) => PostTiming::applyFits($q, $publishAt, PostTiming::MIN_LEAD_HOURS, 'e'))
            ->when($pick === 'interest', fn ($q) => $q
                // ПЕРВИЧНЫЙ интерес внутри дерева темы. Без rank = 0 в спектакли
                // попадает концерт, которому театр проставлен вторым тегом.
                ->where('ei.rank', 0)
                ->whereIn('ei.interest_id', $ids))
            ->when($pick === 'free', fn ($q) => $q
                // donation рядом с free намеренно: «вход свободный, кто сколько
                // может» читатель считает бесплатным, и он прав.
                ->whereIn('e.price_status', ['free', 'donation']))
            ->when($pick === 'price_max', fn ($q) => $q
                // Только там, где цена ИЗВЕСТНА и это число. `unknown` в рубрику
                // про деньги пускать нельзя: обещание «дешевле 500» проверят
                // первым же нажатием.
                ->whereNotNull('e.price_min')
                ->where('e.price_min', '>', 0)
                ->where('e.price_min', '<=', (int) ($theme['price_max'] ?? 500)))
            ->when($pick === 'tod', fn ($q) => $q
                ->whereRaw(
                    "EXTRACT(HOUR FROM e.start_time AT TIME ZONE 'Europe/Moscow') >= ?",
                    [(int) ($theme['hour_from'] ?? 20)],
                ))
            // РУБРИКЕ ПО ПОВОДУ НУЖНО НАЧАЛО ВПЕРЕДИ, а не «идёт до сих пор».
            // Общее правило срока пропускает многодневку, пока она не
            // закрылась, — выставке так и надо. А «Вечером» с таким правилом
            // называет спектакль, начавшийся 8 июля, и строка выходит
            // «ср 8 июля, 20:00»: дата в прошлом под заголовком про эту неделю.
            ->when($pick !== 'interest', fn ($q) => $q->where('e.start_time', '>=', $publishAt))
            ->where(function ($q) {
                $q->whereNull('e.tickets_status')->orWhere('e.tickets_status', '<>', 'sold_out');
            })
            ->where(function ($q) {
                $q->whereNull('e.content_kind')
                    ->orWhereNotIn('e.content_kind', ['official', 'religious']);
            })
            ->orderBy('e.start_time')
            ->get([
                'e.id', 'e.title', 'e.start_time', 'e.event_group_id', 'e.venue_id',
                'e.description', 'e.tg_description', 'e.price_min', 'e.price_max', 'e.price_status',
                'v.name as venue_name',
            ]);

        $rows = $this->rejectStopList($rows);

        // Жанровые отсечки — ТОЛЬКО тематическим рубрикам. Они спрашивают «не
        // чужой ли это жанр для темы», а у «Бесплатно» и «Дешевле 500» жанра
        // нет вовсе: там годится и концерт, и лекция, и экскурсия. Оставь их
        // включёнными — и рубрика выкосит сама себя, потому что у каждого
        // события в описании найдётся слово чужого жанра.
        if ($pick === 'interest') {
            $rows = $this->rejectForeignGenre($rows, (string) $theme['slug']);
            $rows = $this->rejectForeignSourceRubric($rows, (string) $theme['slug']);
        }
        // Остывание отказа — и в подборке тоже: см. rejectRecentlyRejected.
        $rows = $this->rejectRecentlyRejected($broadcast, $rows);

        // $keepShown — для выдачи «уже в ленте»: там нужны как раз те события,
        // которые эта отсечка и снимает. Автосборка её не отключает никогда.
        if (! $keepShown) {
            $rows = $this->rejectAlreadyShown($broadcast, $rows, $exceptItemId);
        }

        return ['rows' => $this->collapseRepeats($rows)];
    }

    /** Дерево интересов темы — тем же рекурсивным обходом, что и лендинг. */
    private function interestTree(string $slug): array
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
     * Событие, от которого канал недавно отказался, рубрика не предлагает.
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

        if ($cold->isEmpty()) {
            return $rows;
        }

        // И по трём ключам, а не по одному id. Пул схлопывает повторы, но
        // ПОСЛЕ этой отсечки: выброшенный спектакль вернулся бы следующей
        // датой того же названия сразу же, и кнопка «больше не предлагать»
        // выглядела бы сломанной.
        $ids = $cold->pluck('id')->map(fn ($v) => (int) $v)->all();
        $groups = $cold->pluck('event_group_id')->filter()->map(fn ($v) => (int) $v)->all();
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
    ): \Illuminate\Support\Collection {
        $shown = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('telegram.chat_broadcast_items as i', 'i.id', '=', 'l.item_id')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('i.broadcast_id', $broadcast->id)
            // Собственный состав записи — не «уже показанное». Без этого
            // повторная сборка той же подборки НИКОГДА не выбирает ту же
            // тройку: она только что сама её и закрыла.
            ->when($exceptItemId !== null, fn ($q) => $q->where('i.id', '<>', $exceptItemId))
            ->where(function ($q) {
                $q->whereIn('i.status', [
                    TelegramChatBroadcastItem::STATUS_PENDING,
                    TelegramChatBroadcastItem::STATUS_PLANNED,
                    TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                    TelegramChatBroadcastItem::STATUS_APPROVED,
                    TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                ])->orWhere(function ($w) {
                    $w->where('i.status', TelegramChatBroadcastItem::STATUS_POSTED)
                        ->where('i.posted_at', '>=', Carbon::now()->subDays(self::SHOWN_WINDOW_DAYS));
                });
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
     * Три события, которые назовём поимённо.
     *
     * Одна строка на площадку и одна на день: подборка о разнообразии недели, и
     * три спектакля одного театра подряд — это афиша театра.
     *
     * Внутри — по длине описания. Честно: это мера ПОЛНОТЫ карточки, а не
     * качества события, и вдобавок мера источника — у импорта Яндекс.Афиши
     * описания в разы длиннее (684 символа против 46 у Никитинки), поэтому
     * длинные строки систематически приводят его.
     *
     * Скорер ленты вместо неё НЕ берём, но не потому, что он «раздаёт всем
     * одинаковые баллы» — это было неверно и переоткрывало вопрос. Замер
     * 2026-09-15: на очищенном пуле он даёт 7 разных баллов в концертах
     * (70..115) и 8 в спектаклях (65..120), то есть разделяет. Не берём
     * потому, ЧТО он складывает: фото, ФИАС, площадку, цену — ту же полноту
     * карточки, — и в его топе оказываются вечеринки и квест-комнаты. Плюс
     * штраф за близость даты топит выходные: 59% кандидатов недели приходятся
     * на пт-вс и получают минус пять.
     *
     * Поведенческого сигнала в базе нет ВООБЩЕ: event_attendees, interest_user
     * и context_interactions пусты, просмотров и лайков схема не хранит.
     * Значит «лучшее» тут измерить нечем, и притворяться, что умеем
     * ранжировать качество, не надо.
     *
     * @return list<object>
     */
    /**
     * @param  array{0:int,1:int}  $range  сколько назвать: минимум и максимум
     */
    private function pickNamed(\Illuminate\Support\Collection $rows, array $range = [3, 3]): array
    {
        [$min, $max] = $range;
        $minDescription = (int) config('broadcast_digest.min_description', 120);

        $ranked = $rows
            ->filter(fn ($r) => mb_strlen(trim((string) $r->description)) >= $minDescription)
            ->sortByDesc(fn ($r) => mb_strlen(trim((string) $r->description)))
            ->values();

        // Полных карточек может не хватить — добираем остальными, иначе тема с
        // хорошим составом отваливалась бы из-за коротких описаний.
        $pool = $ranked->concat($rows->reject(
            fn ($r) => $ranked->contains(fn ($x) => $x->id === $r->id)
        ));

        $named = [];
        $venues = [];
        $days = [];

        foreach ($pool as $row) {
            $venue = $row->venue_id !== null ? (int) $row->venue_id : null;
            $day = Carbon::parse($row->start_time)->setTimezone(self::TZ)->toDateString();

            // Без площадки не называем вовсе. Такая строка никогда не
            // блокировалась правилом «одна площадка — одна строка» и никогда
            // не занимала площадку: три события без места прошли бы все три
            // отсечки и встали рядом. А читателю строка «название · дата» без
            // места говорит ровно половину нужного.
            if ($venue === null) {
                continue;
            }
            if (isset($venues[$venue])) {
                continue;
            }
            if (isset($days[$day])) {
                continue;
            }

            // ЧИСЛО НАЗВАННЫХ ПЛАВАЕТ, но не наугад. Сверх минимума берём
            // только того, кому есть что сказать: у события должно хватать
            // описания на строку-изюм. Иначе четвёртым встанет голое
            // «название · дата · место» — и пост выглядит недоделанным, а не
            // щедрым. Подпись это тоже бережёт: замер на живой подборке —
            // шапка с подвалом 147 знаков, каждое названное с фразой около
            // 176, при пороге 950 пятое уже не влезает.
            $full = mb_strlen(trim((string) $row->description)) >= $minDescription;
            if (count($named) >= $min && ! $full) {
                continue;
            }

            $named[] = $row;
            if ($venue !== null) {
                $venues[$venue] = true;
            }
            $days[$day] = true;

            if (count($named) >= $max) {
                break;
            }
        }

        // По датам: подборка отвечает на вопрос «что впереди», и читать её
        // удобно по порядку недели. Отбор шёл по полноте карточки, поэтому
        // порядок на выходе был случайным — сначала суббота, потом среда.
        usort($named, fn ($a, $b) => strcmp((string) $a->start_time, (string) $b->start_time));

        return $named;
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
        $from = $publishAt->copy()->setTimezone(self::TZ);
        // Срок в шапке — окно ЭТОЙ рубрики, а не общее. У «Бесплатно» оно
        // пятидневное, и шапка «25 сентября – 2 октября» обещала бы восемь
        // дней там, где отбор смотрит пять.
        $to = $from->copy()->addDays(
            (int) ($theme['window_days'] ?? config('broadcast_digest.window_days', 7)),
        );
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

        $head = trim(($theme['emoji'] ?? '').' <b>'.$this->escape($headline).'</b>')
            .' · '.$this->escape($range);

        // Подводка ведущего — про эту неделю и эту тройку. Её место занимала
        // строка счёта («20 концертов на 10 площадках. Три — в разных местах и
        // в разные дни»), и та говорила две вещи, которых читатель не просил:
        // хвасталась охватом и пересказывала внутреннее правило отбора. Счёт
        // переехал в подвал, где он работает поводом нажать.
        // ПРОВЕРЕННАЯ подводка, а не любая. Сборка зовётся ДО того, как мету
        // почистят от текста под прежний состав, — без проверки старая
        // подводка уходит в подпись, а из меты потом исчезает: в админке
        // видно одно, в канал уезжает другое.
        $namedIds = array_map(fn ($r) => (int) $r->id, $picked['named']);
        $lead = $item?->digestIntroFor($namedIds);

        $lines = [];
        $rich = [];
        foreach ($picked['named'] as $row) {
            $at = Carbon::parse($row->start_time)->setTimezone(self::TZ);
            $meta = array_values(array_filter([
                self::WEEKDAYS[(int) $at->isoWeekday()].' '.$at->day.' '.self::MONTHS[$at->month].', '.$at->format('H:i'),
                $this->venueLabel($row),
                $this->priceLabel($row),
            ]));

            // НЕ $head: этим именем выше назван заголовок поста, и повторное
            // использование затирало его названием последнего события.
            $titleLink = '<b>'.$this->link($this->eventUrl((int) $row->id, $item?->id), (string) $row->title).'</b>';
            $facts = '<i>'.$this->escape(implode(' · ', $meta)).'</i>';
            $hook = $this->hook($row, $item);

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
        $footer = $this->link(
            $this->landingUrl($broadcast, (string) $theme['slug'], $item?->id, $theme),
            'Вся афиша '.($forms[2] ?? mb_strtolower((string) $theme['title'])),
        );

        $top = array_values(array_filter([$head, $lead !== null ? $this->escape($lead) : null]));

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

        for ($keep = count($rich); $keep >= 0; $keep--) {
            $body = array_merge(
                array_slice($rich, 0, $keep),
                array_slice($lines, $keep),
            );
            $caption = implode("\n\n", array_merge($top, $body, [$footer]));

            if ($keep === 0 || \App\Support\Telegram\CaptionLength::visible($caption) <= $soft) {
                return $caption;
            }
        }

        return implode("\n\n", array_merge($top, $lines, [$footer]));
    }

    /**
     * Строка-изюм про событие: чем оно цепляет.
     *
     * Четыре источника по старшинству — блок ИЗЮМ в config/broadcast_digest.php.
     * Сами ничего не сочиняем: подборка и так рискует звучать списком из базы,
     * а выдуманная фраза к этому добавит вранья.
     */
    private function hook(object $row, ?TelegramChatBroadcastItem $item = null): string
    {
        $limit = (int) config('broadcast_digest.named_line_chars', 130);

        // 1. Строка модели написана ПОД ЭТУ СТРОКУ — целиком, а не первой
        // фразой из анонса. Резать её по первому предложению нельзя: панч у
        // такой строки обычно во второй половине.
        $written = $item?->digestHook((int) $row->id);
        if ($written !== null) {
            return $this->typography($this->trimToWord((string) preg_replace('/\s+/u', ' ', $written), (int) round($limit * 1.3)));
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

    private function priceLabel(object $row): string
    {
        if ($row->price_status === 'free') {
            return 'бесплатно';
        }

        $min = $row->price_min !== null ? (int) $row->price_min : null;
        $max = $row->price_max !== null ? (int) $row->price_max : null;

        if ($min === null && $max === null) {
            return '';
        }

        // Неразрывный пробел перед знаком: на узком экране строка фактов
        // переносится, и «4900» уезжало на одну строку, а «₽» на другую.
        $amount = (string) ($min ?? $max);

        return $min !== null && $max !== null && $min !== $max
            ? 'от '.$min."\u{00A0}₽"
            : $amount."\u{00A0}₽";
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
    private function landingUrl(TelegramChatBroadcast $broadcast, string $slug, ?int $itemId = null, ?array $theme = null): string
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
        $landing = (string) ($theme['landing'] ?? '');

        $url = match (true) {
            $landing !== '' => $base.$landing,
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
