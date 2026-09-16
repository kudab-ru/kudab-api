<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Собрать подборку недели: тему, состав и текст.
 *
 * КОГДА. Перед самой отправкой, а не при постановке. Подборка, собранная за
 * неделю, показывает пятую часть недели: в следующей неделе событий вчетверо
 * меньше, чем в текущей (замер в docs/broadcast-admin/CADENCE.md).
 *
 * ЧТО ПОКАЗЫВАЕТ. Три события поимённо, остальное числом. Это не приём
 * оформления, а решение о размере задачи: названное событие закрывается для
 * собственного поста, и называть десять — значит забрать у ленты десять постов.
 *
 * ТЕМА. Только из реестра лендингов (config/broadcast_digest.php): заголовок
 * звучит по-человечески, подвал ведёт на существующую страницу, и SEO-трафик
 * идёт туда же, куда подписчики.
 */
final class BroadcastDigestComposer
{
    private const TZ = 'Europe/Moscow';

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
     *                                                                                                                 null — ни одна тема не набрала состава; решать, что делать дальше, вызывающему
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

        $best = null;

        foreach ((array) config('broadcast_digest.themes', []) as $theme) {
            $picked = $this->pickForTheme($broadcast, (int) $cityId, (array) $theme, $publishAt, $forItem?->id);
            if ($picked === null) {
                continue;
            }

            // Тему выбираем по числу РАЗНЫХ площадок, а не событий: пять
            // концертов в одном баре — это афиша бара, а не тема недели.
            if ($best === null || $picked['venues'] > $best['venues']) {
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
     *                                                                                                                                     null — состав рассыпался (события удалены или прошли) либо темы больше нет: решать вызывающему
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
        if ($named === []) {
            return null;
        }

        // Счётчики пула — заново на момент отправки. Разойтись с замороженным
        // составом они не могут: состав входит в пул, а «20 концертов» — это
        // сколько их всего, а не сколько выбрано.
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
     * События, которые успели удалить или которые уже начались, выпадают:
     * ссылка на удалённое событие ведёт в 404, а «сегодня в 19:00» про то, что
     * началось час назад, — вранью в канале.
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
            ->where('e.start_time', '>=', $publishAt)
            ->get([
                'e.id', 'e.title', 'e.start_time', 'e.event_group_id', 'e.venue_id',
                'e.description', 'e.tg_description', 'e.price_min', 'e.price_max', 'e.price_status',
                'v.name as venue_name',
            ])
            ->keyBy(fn ($r) => (int) $r->id);

        $named = [];
        foreach ($ids as $id) {
            if ($rows->has($id)) {
                $named[] = $rows->get($id);
            }
        }

        usort($named, fn ($a, $b) => strcmp((string) $a->start_time, (string) $b->start_time));

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
            'named' => $this->pickNamed($rows),
            'total' => $total,
            'venues' => $venues,
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
    ): ?array {
        $ids = $this->interestTree((string) $theme['interest']);
        if ($ids === []) {
            return null;
        }

        $until = $publishAt->copy()->addDays((int) config('broadcast_digest.window_days', 7));

        $rows = DB::table('events as e')
            ->join('event_interest as ei', 'ei.event_id', '=', 'e.id')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->join('communities as c', 'c.id', '=', 'e.community_id')
            ->whereNull('e.deleted_at')
            ->where('e.status', 'active')
            ->where('c.city_id', $cityId)
            ->where('e.start_time', '>=', $publishAt)
            ->where('e.start_time', '<=', $until)
            // ПЕРВИЧНЫЙ интерес внутри дерева темы. Без rank = 0 в спектакли
            // попадает концерт, которому театр проставлен вторым тегом.
            ->where('ei.rank', 0)
            ->whereIn('ei.interest_id', $ids)
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
        $rows = $this->rejectForeignGenre($rows, (string) $theme['slug']);
        $rows = $this->rejectForeignSourceRubric($rows, (string) $theme['slug']);
        $rows = $this->rejectAlreadyShown($broadcast, $rows, $exceptItemId);

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

    /**
     * Заголовки не по теме. Мастер-класс с тегом «театр» — это мастер-класс, и
     * в подборке спектаклей он читается как ошибка отбора.
     */
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
     * Разметка интересов ошибается систематически — у квеста «Припять 36» тема
     * «музыка» проставлена 235 раз, — и переголосовать её повторами нельзя:
     * повторы ошибаются одинаково. Зато событие само говорит, что оно такое, и
     * говорит в первой строке. Если названный жанр принадлежит другой теме
     * реестра, событие не наше, какой бы тег ему ни поставили.
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
     * Яндекс.Афиша кладёт рубрику в разметку страницы, и она точнее нашего
     * тегера: по рубрике `quest` первичный интерес «музыка» проставлен 829
     * раз. Именно так квест-комната «Припять 36» попала в подборку концертов.
     * Проверка описания ловит только тех, кто называет жанр в первой строке, —
     * это меньше трети случаев; источник знает про все.
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
                        ->where('i.posted_at', '>=', Carbon::now()->subDays(7));
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
    private function pickNamed(\Illuminate\Support\Collection $rows): array
    {
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

            $named[] = $row;
            if ($venue !== null) {
                $venues[$venue] = true;
            }
            $days[$day] = true;

            if (count($named) >= (int) config('broadcast_digest.named', 3)) {
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
        $to = $from->copy()->addDays((int) config('broadcast_digest.window_days', 7));
        $forms = (array) ($theme['forms'] ?? []);

        // Шапка одной строкой: тема и период. Город не пишем — канал
        // городской, а площадки в строках фактов и так его называют.
        // «15–22 сентября»: месяц повторяется, только когда он другой.
        $range = $from->month === $to->month
            ? sprintf('%d–%d %s', $from->day, $to->day, self::MONTHS[$to->month])
            : sprintf('%d %s – %d %s', $from->day, self::MONTHS[$from->month], $to->day, self::MONTHS[$to->month]);

        $head = trim(($theme['emoji'] ?? '').' <b>'.$this->escape((string) $theme['title']).' недели</b>')
            .' · '.$this->escape($range);

        // Подводка ведущего — про эту неделю и эту тройку. Её место занимала
        // строка счёта («20 концертов на 10 площадках. Три — в разных местах и
        // в разные дни»), и та говорила две вещи, которых читатель не просил:
        // хвасталась охватом и пересказывала внутреннее правило отбора. Счёт
        // переехал в подвал, где он работает поводом нажать.
        $lead = $item?->digestIntro();

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
            $titleLink = '<b>'.$this->link($this->eventUrl((int) $row->id), (string) $row->title).'</b>';
            $facts = '<i>'.$this->escape(implode(' · ', $meta)).'</i>';
            $hook = $this->hook($row, $item);

            // Имя, под ним ведомость фактов, и только потом живая строка: блок
            // заканчивается человеческой фразой, а не ценой. Тот же приём, что
            // промпт канала называет «панч отдельной строкой», но в вёрстке.
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
            $this->landingUrl($broadcast, (string) $theme['slug'], $item?->id),
            'Вся афиша '.($forms[2] ?? mb_strtolower((string) $theme['title'])),
        );

        $top = array_values(array_filter([$head, $lead !== null ? $this->escape($lead) : null]));

        // С изюмом, если он влезает. Подпись к альбому Telegram режет на 1024
        // символах, и обрезанная на полуслове строка хуже её отсутствия —
        // поэтому снимаем изюм целиком, а не подрезаем.
        $withHooks = implode("\n\n", array_merge($top, $rich, [$footer]));
        $soft = (int) config('broadcast_digest.caption_soft_limit', 950);

        if (mb_strlen(strip_tags($withHooks)) <= $soft) {
            return $withHooks;
        }

        return implode("\n\n", array_merge($top, $lines, [$footer]));
    }

    /**
     * Строка-изюм про событие: чем оно цепляет.
     *
     * Берём готовый ТГ-анонс, если он есть, иначе первое предложение описания.
     * Ничего не сочиняем: подборка и так рискует звучать списком из базы, а
     * выдуманная фраза к этому добавит вранья.
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
        // Точка после одной заглавной буквы или сокращения концом не считается:
        // иначе изюм обрывался на «по пьесе В.» и «Воронежский театр кукол им.»
        // — так в подборку уходило 5% строк.
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
     * Пресс-релиз начинается одинаково — «Приглашаем юных слушателей и их
     * родителей на концерт с участием артистов…», — и такая строка в посте
     * читается как перепечатка, потому что она ею и является. Пропускаем такие
     * зачины и берём следующее предложение; не нашлось живого — строки не
     * будет вовсе. Пустое место честнее чужой вежливости.
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

        // Метки вместо точек, которые концом предложения не являются.
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

        return rtrim(mb_substr($sentence, 0, $at), " ,;:—–-").'.';
    }

    /** Режем по слову, а не по символу: «спекта…» читается как сбой. */
    private function trimToWord(string $text, int $limit): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $at = mb_strrpos($cut, ' ');

        return rtrim($at === false ? $cut : mb_substr($cut, 0, $at), " ,;:—-").'…';
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
     * Имя площадки без мусора источника.
     *
     * Афиши складывают в одно поле два названия одного места («Arena Hall /
     * Aura Night Club») и приклеивают город через трубу («Новый театр |
     * Воронеж»). В строке фактов, которую подборка ставит под каждым именем,
     * это самое заметное место поста.
     */
    private function venueLabel(object $row): string
    {
        $name = trim((string) ($row->venue_name ?? ''));
        if ($name === '') {
            return '';
        }

        foreach ([' | ', ' / ', ' — филиал'] as $mark) {
            $at = mb_strpos($name, $mark);
            if ($at !== false && $at >= 3) {
                $name = trim(mb_substr($name, 0, $at));
            }
        }

        return $name;
    }

    /**
     * @param  int|null  $itemId  номер записи в метке `utm_content`: по нему
     *                            Метрика отличает переходы ЭТОЙ подборки от
     *                            прошлой — иначе рубрика измерима только целиком
     */
    private function landingUrl(TelegramChatBroadcast $broadcast, string $slug, ?int $itemId = null): string
    {
        $base = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');
        $citySlug = $broadcast->chat?->city?->slug ?? '';
        $utm = (array) config('broadcast_digest.utm', []);

        $url = $citySlug !== ''
            ? $base.'/afisha/'.$citySlug.'/'.$slug
            : $base.'/events';

        $url .= '?utm_source='.($utm['source'] ?? 'tg').'&utm_medium='.($utm['medium'] ?? 'digest');

        return $itemId === null ? $url : $url.'&utm_content=i'.$itemId;
    }

    private function eventUrl(int $id): string
    {
        return rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/').'/events/'.$id;
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
