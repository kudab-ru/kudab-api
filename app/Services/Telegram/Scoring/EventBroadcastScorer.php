<?php

namespace App\Services\Telegram\Scoring;

use App\Models\Event;
use App\Services\Telegram\PostTiming;
use Carbon\Carbon;

/**
 * Насколько событие интересно каналу, без LLM: вид, разовое или каждую неделю, продаёт ли его касса,
 * сколько у него оценок на Яндекс Афише. Полнота карточки даёт только штрафы: без афиши или точного
 * времени пост выходит слабым. Признаки — EventInterestSignals, жёсткие фильтры — в выборке кандидатов.
 */
class EventBroadcastScorer
{
    /** Вид по первичной теме или её родителю; чего нет в списке — ноль. */
    public const KIND_WEIGHTS = [
        'music' => 30,
        'festival' => 30,
        'theatre' => 25,
        'standup' => 25,
        'circus-show' => 20,
        'parties' => 15,
        'city' => 5,
        'sport' => 5,
        'exhibitions' => 5,
        'quiz-games' => -5,
        'kids' => -5,
        'education' => -15,
        'excursions' => -15,
    ];

    public const W_ONE_OFF = 20; // одна дата за месяц

    public const W_FEW_DATES = 15; // две-три даты: тоже редкость

    public const W_WEEKLY = -20; // каждую неделю и чаще

    public const W_PERMANENT = -40; // идёт постоянно: квест-комнаты, сеансы выставки

    public const W_TICKETED = 10;

    public const W_RATED = 10;

    public const W_RATED_MUCH = 20;

    public const W_QUEST_ROOM = -20;

    public const W_KIDS_ONLY = -10; // у канала взрослый читатель

    public const W_FREE_WANTED = 10; // бесплатное, пока на неделе такого нет

    public const W_NO_PHOTO = -30; // фото — главный повод открыть пост в телеграме

    public const W_SHORT_TEXT = -10;

    public const W_NO_TIME = -10;

    public const W_NO_VENUE = -5;

    public const WEEKLY_FROM = 4;

    public const PERMANENT_FROM = 15;

    public const RATED_FROM = 50;

    public const RATED_MUCH_FROM = 150;

    public const MIN_DESCRIPTION_LEN = 120;

    /** Прокат редок только в последние дни: столько дней до закрытия — «последний шанс». */
    public const LAST_CHANCE_DAYS = 7;

    /** Вид площадки из справочника, у которой каждый сеанс — отдельное событие. */
    private const QUEST_VENUE_KIND = 'Квесты';

    /** Штраф за дальность старта: [максимум_дней => штраф], дальше последнего порога — FRESHNESS_FAR. */
    private const FRESHNESS_TIERS = [
        2 => 0, // сегодня / завтра / послезавтра
        7 => -5,
        30 => -12,
    ];

    private const FRESHNESS_FAR = -25; // > 30 дней

    /** Идущий прокат штрафуется по дням до закрытия, но не сильнее −12: он идёт сейчас, а не через месяц. */
    private const RUN_TIERS = [
        2 => 0,
        7 => -5,
    ];

    private const RUN_FAR = -12;

    /** Дни свежести — по московскому календарю: в UTC ночное событие попало бы на день раньше. */
    private const TZ = 'Europe/Moscow';

    private const NO_SIGNALS = [
        'slug' => null, 'parent' => null, 'repeats' => 1, 'ticketed' => false,
        'rating' => 0, 'photo' => false, 'venue_kind' => null,
    ];

    /** @var array<int, array<string, mixed>> */
    private array $signalsById = [];

    // необязательный параметр контейнер оставил бы пустым, и все признаки молча стали бы нулями
    public function __construct(private readonly EventInterestSignals $signals) {}

    /**
     * Признаки событий одним запросом. Прежние забываются: долгий процесс не должен жить на
     * устаревших. Кто не позвал, тому rank() догрузит недостающие сам.
     *
     * @param  iterable<object>  $events
     */
    public function prepare(iterable $events): void
    {
        $ids = [];
        foreach ($events as $e) {
            $ids[] = (int) $e->id;
        }
        $this->signalsById = $this->signals?->forEvents($ids) ?? [];
    }

    /** @param  array<int, array<string, mixed>>  $signalsById  признаки, известные заранее, — для прогона без базы */
    public function remember(array $signalsById): void
    {
        $this->signalsById = $signalsById + $this->signalsById;
    }

    /** $at — момент поста: от его дня считается свежесть. */
    public function score(Event $e, Carbon $at, bool $freeWanted = false): int
    {
        return array_sum($this->breakdown($e, $at, $freeWanted));
    }

    /**
     * Балл по слагаемым, сумма — score(). По слагаемым и explain() PickReason пишет, почему выбрано.
     *
     * @return array<string, int>
     */
    public function breakdown(Event $e, Carbon $at, bool $freeWanted = false): array
    {
        return $this->parts($e, $freeWanted, $at) + ['freshness' => $this->freshnessDelta($e, $at)];
    }

    /** Интерес без поправки на дату: порядок внутри дня и подборки, у которых окно задаёт рубрика. */
    public function interest(object $e): int
    {
        return array_sum($this->parts($e, false));
    }

    /**
     * Что сказать человеку о событии.
     *
     * @return array{kind: string|null, repeats: int, ticketed: bool, rating: int, multi_day: bool}
     */
    public function explain(object $e): array
    {
        $s = $this->signal($e);

        return [
            'kind' => $s['parent'] ?? $s['slug'],
            'repeats' => (int) $s['repeats'],
            'ticketed' => (bool) $s['ticketed'],
            'rating' => (int) $s['rating'],
            'multi_day' => self::isRun($e),
        ];
    }

    /** @param  iterable<Event>  $events */
    public function pickBest(iterable $events, Carbon $at, bool $freeWanted = false): ?Event
    {
        return $this->rank($events, $at, $freeWanted)[0] ?? null;
    }

    /**
     * От лучшего к худшему: балл, при равном обычное событие раньше идущего проката (начало у проката
     * всегда раньше, и ничья уходила бы ему), дальше ближайшее начало, у проката закрытие.
     * При полном равенстве меньший номер события: порядок строк с одним началом база не обещает.
     *
     * @param  iterable<Event>  $events
     * @return list<Event>
     */
    public function rank(iterable $events, Carbon $at, bool $freeWanted = false): array
    {
        $events = is_array($events) ? array_values($events) : iterator_to_array($events, false);
        $this->ensure($events);

        $rows = [];
        foreach ($events as $e) {
            $closesAt = PostTiming::runningUntil($e, $at);
            $rows[] = [
                [
                    -$this->score($e, $at, $freeWanted),
                    $closesAt === null ? 0 : 1,
                    ($closesAt ?? $e->start_time)?->getTimestamp() ?? PHP_INT_MAX,
                    (int) $e->id,
                ],
                $e,
            ];
        }

        usort($rows, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_column($rows, 1);
    }

    /**
     * Строки пула подборки по интересу. При равном раньше та, у которой есть свой анонс, потом
     * раньше начало.
     *
     * @param  list<object>  $rows
     * @return list<object>
     */
    public function rankRows(array $rows): array
    {
        $this->prepare($rows);

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[] = [
                [
                    -$this->interest($row),
                    trim((string) ($row->tg_description ?? '')) !== '' ? 0 : 1,
                    (string) $row->start_time,
                    (int) $row->id,
                ],
                $row,
            ];
        }

        usort($keyed, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_column($keyed, 1);
    }

    /** Через сколько дней после дня поста закрывается прокат, который к посту уже идёт; иначе null. */
    public function runDaysLeft(Event $e, Carbon $at): ?int
    {
        $closesAt = PostTiming::runningUntil($e, $at);

        return $closesAt === null ? null : $this->daysAfter($at, $closesAt);
    }

    /**
     * @param  object  $e  событие или строка выборки с теми же колонками
     * @param  Carbon|null  $at  момент поста; без него прокат не получает «последний шанс»
     * @return array<string, int>
     */
    private function parts(object $e, bool $freeWanted, ?Carbon $at = null): array
    {
        $s = $this->signal($e);
        $repeats = (int) $s['repeats'];
        $rating = (int) $s['rating'];

        return [
            'kind' => self::KIND_WEIGHTS[$s['slug'] ?? ''] ?? self::KIND_WEIGHTS[$s['parent'] ?? ''] ?? 0,
            'dates' => self::isRun($e) ? $this->lastChance($e, $at) : match (true) {
                $repeats <= 1 => self::W_ONE_OFF,
                $repeats < self::WEEKLY_FROM => self::W_FEW_DATES,
                $repeats < self::PERMANENT_FROM => self::W_WEEKLY,
                default => self::W_PERMANENT,
            },
            'ticketed' => $s['ticketed'] ? self::W_TICKETED : 0,
            'rating' => match (true) {
                $rating >= self::RATED_MUCH_FROM => self::W_RATED_MUCH,
                $rating >= self::RATED_FROM => self::W_RATED,
                default => 0,
            },
            'quest' => $s['venue_kind'] === self::QUEST_VENUE_KIND ? self::W_QUEST_ROOM : 0,
            'kids' => ($e->audience ?? null) === 'kids' ? self::W_KIDS_ONLY : 0,
            'free' => $freeWanted && in_array($e->price_status ?? null, ['free', 'donation'], true) ? self::W_FREE_WANTED : 0,
            'photo' => $s['photo'] ? 0 : self::W_NO_PHOTO,
            'text' => mb_strlen(trim((string) ($e->description ?? ''))) >= self::MIN_DESCRIPTION_LEN ? 0 : self::W_SHORT_TEXT,
            // у события без времени в начале полночь, и в посте вышло бы «сб 00:00»
            'time' => ($e->time_precision ?? null) === 'date' ? self::W_NO_TIME : 0,
            'venue' => empty($e->venue_id) ? self::W_NO_VENUE : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function signal(object $e): array
    {
        $id = (int) $e->id;
        if (! isset($this->signalsById[$id]) && $this->signals !== null) {
            $this->signalsById += $this->signals->forEvents([$id]);
        }

        return $this->signalsById[$id] ?? self::NO_SIGNALS;
    }

    /** @param  list<object>  $events */
    private function ensure(array $events): void
    {
        $missing = [];
        foreach ($events as $e) {
            if (! isset($this->signalsById[(int) $e->id])) {
                $missing[] = (int) $e->id;
            }
        }
        if ($missing !== [] && $this->signals !== null) {
            $this->signalsById += $this->signals->forEvents($missing);
        }
    }

    /** Прокат — от недели: фестиваль на два-три дня редок, как разовое событие. */
    private static function isRun(object $e): bool
    {
        if (empty($e->start_time) || empty($e->end_time)) {
            return false;
        }

        return Carbon::parse($e->end_time)->gte(Carbon::parse($e->start_time)->addDays(PostTiming::RUN_MIN_DAYS));
    }

    private function lastChance(object $e, ?Carbon $at): int
    {
        if ($at === null) {
            return 0;
        }
        $end = Carbon::parse($e->end_time);

        return $end->gte($at) && $end->lte($at->copy()->addDays(self::LAST_CHANCE_DAYS)) ? self::W_ONE_OFF : 0;
    }

    private function freshnessDelta(Event $e, Carbon $at): int
    {
        if ($e->start_time === null) {
            return self::FRESHNESS_FAR;
        }

        $daysLeft = $this->runDaysLeft($e, $at);
        if ($daysLeft !== null) {
            return $this->penalty($daysLeft, self::RUN_TIERS, self::RUN_FAR);
        }

        return $this->penalty($this->daysAfter($at, $e->start_time), self::FRESHNESS_TIERS, self::FRESHNESS_FAR);
    }

    /** @param array<int, int> $tiers */
    private function penalty(int $days, array $tiers, int $far): int
    {
        foreach ($tiers as $maxDays => $penalty) {
            if ($days <= $maxDays) {
                return $penalty;
            }
        }

        return $far;
    }

    private function daysAfter(Carbon $from, Carbon $to): int
    {
        return (int) $from->copy()->setTimezone(self::TZ)->startOfDay()
            ->diffInDays($to->copy()->setTimezone(self::TZ)->startOfDay(), false);
    }
}
