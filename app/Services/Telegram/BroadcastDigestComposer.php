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
    public function compose(TelegramChatBroadcast $broadcast, Carbon $publishAt): ?array
    {
        $cityId = $broadcast->chat?->city_id;
        if (! $cityId) {
            return null;
        }

        $best = null;

        foreach ((array) config('broadcast_digest.themes', []) as $theme) {
            $picked = $this->pickForTheme($broadcast, (int) $cityId, (array) $theme, $publishAt);
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
            'caption' => $this->buildCaption($broadcast, $best, $publishAt),
            'event_ids' => array_map(fn ($e) => (int) $e->id, $best['named']),
            'total' => $best['total'],
            'venues' => $best['venues'],
        ];
    }

    /**
     * Отбор по одной теме. Возвращает null, если тема не прошла гейт.
     *
     * @param  array<string, string>  $theme
     * @return array{theme: array<string, string>, named: list<object>, total: int, venues: int}|null
     */
    private function pickForTheme(TelegramChatBroadcast $broadcast, int $cityId, array $theme, Carbon $publishAt): ?array
    {
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
                'e.description', 'e.price_min', 'e.price_max', 'e.price_status',
                'v.name as venue_name',
            ]);

        $rows = $this->rejectStopList($rows);
        $rows = $this->rejectAlreadyShown($broadcast, $rows);
        $rows = $this->collapseRepeats($rows);

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
     * Вычесть то, что канал уже показал или вот-вот покажет.
     *
     * По ТРЁМ ключам: сам идентификатор, группа повторов и нормализованный
     * заголовок. Одного мало — то же событие приезжает из разных источников
     * разными строками, и подборка стала бы оглавлением уже прочитанного.
     */
    private function rejectAlreadyShown(TelegramChatBroadcast $broadcast, \Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        $shown = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('telegram.chat_broadcast_items as i', 'i.id', '=', 'l.item_id')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('i.broadcast_id', $broadcast->id)
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
     * три спектакля одного театра подряд — это афиша театра. Внутри — по
     * полноте карточки, а не по скореру ленты: скорер меряет заполненность и
     * на живой выборке раздаёт всем одинаковые баллы, а его штраф за близость
     * даты систематически топит выходные.
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

            if ($venue !== null && isset($venues[$venue])) {
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

        return $named;
    }

    /** @param array{theme: array<string, string>, named: list<object>, total: int, venues: int} $picked */
    private function buildCaption(TelegramChatBroadcast $broadcast, array $picked, Carbon $publishAt): string
    {
        $theme = $picked['theme'];
        $city = $broadcast->chat?->city?->name ?? '';
        $from = $publishAt->copy()->setTimezone(self::TZ);
        $to = $from->copy()->addDays((int) config('broadcast_digest.window_days', 7));

        $head = trim(($theme['emoji'] ?? '').' <b>'.$this->escape((string) $theme['title']).' недели</b>');

        // «С 21 по 28 сентября», а не «с 21 сентября по 28 сентября»: месяц
        // повторяется только когда он действительно другой.
        $range = $from->month === $to->month
            ? sprintf('С %d по %d %s', $from->day, $to->day, self::MONTHS[$to->month])
            : sprintf('С %d %s по %d %s', $from->day, self::MONTHS[$from->month], $to->day, self::MONTHS[$to->month]);

        $forms = (array) ($theme['forms'] ?? []);

        $period = sprintf(
            '%s%s %s на %s. Три — в разных местах и в разные дни:',
            $range,
            $city !== '' ? ' в '.$this->escape($this->cityInflected($city)) : '',
            $this->plural($picked['total'], $forms[0] ?? '', $forms[1] ?? null, $forms[2] ?? null),
            // Предложный падеж: «на 6 площадкАХ», а не «на 6 площадок».
            $this->plural($picked['venues'], 'площадке', 'площадках', 'площадках'),
        );

        $lines = [];
        foreach ($picked['named'] as $row) {
            $at = Carbon::parse($row->start_time)->setTimezone(self::TZ);
            $meta = array_values(array_filter([
                self::WEEKDAYS[(int) $at->isoWeekday()].' '.$at->day.' '.self::MONTHS[$at->month].', '.$at->format('H:i'),
                trim((string) ($row->venue_name ?? '')),
                $this->priceLabel($row),
            ]));

            $lines[] = $this->link($this->eventUrl((int) $row->id), (string) $row->title)
                ."\n".$this->escape(implode(' · ', $meta));
        }

        // Город в подвале не склоняем и не повторяем: он уже назван строкой
        // выше, а «афиша спектаклей Воронеже» — ровно та мелочь, из-за которой
        // пост читается машинным.
        $footer = $this->link(
            $this->landingUrl($broadcast, (string) $theme['slug']),
            'Вся афиша '.($forms[2] ?? mb_strtolower((string) $theme['title'])),
        );

        return implode("\n\n", array_merge([$head, $period], $lines, [$footer]));
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
        if ($min !== null && $max !== null && $min !== $max) {
            return 'от '.$min.' ₽';
        }

        return (string) ($min ?? $max).' ₽';
    }

    private function landingUrl(TelegramChatBroadcast $broadcast, string $slug): string
    {
        $base = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');
        $citySlug = $broadcast->chat?->city?->slug ?? '';
        $utm = (array) config('broadcast_digest.utm', []);

        $url = $citySlug !== ''
            ? $base.'/afisha/'.$citySlug.'/'.$slug
            : $base.'/events';

        return $url.'?utm_source='.($utm['source'] ?? 'tg').'&utm_medium='.($utm['medium'] ?? 'digest');
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

    /** «в Воронеже» — предложный падеж для города. */
    private function cityInflected(string $city): string
    {
        return match (true) {
            str_ends_with($city, 'ж'), str_ends_with($city, 'к'), str_ends_with($city, 'г') => $city.'е',
            str_ends_with($city, 'а') => mb_substr($city, 0, -1).'е',
            str_ends_with($city, 'ь') => mb_substr($city, 0, -1).'и',
            default => $city.'е',
        };
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
