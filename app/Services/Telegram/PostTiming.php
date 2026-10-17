<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use Carbon\Carbon;
use DateTimeInterface;

/**
 * Успевает ли пост к своему событию: одно правило для всех путей, которые
 * назначают записи день. «Не позже начала» действует и на человека,
 * MIN_LEAD_HOURS просит только автомат. Срок многодневного события — конец.
 */
final class PostTiming
{
    /** Запас до начала, который просит автомат: утром про вечер ещё можно, за час — уже нет. */
    public const MIN_LEAD_HOURS = 6;

    /** Дольше суток — значит не вечер, а прокат: успевать надо к закрытию. */
    public const MULTI_DAY_HOURS = 24;

    /** Автоподбор считает идущим прокат от недели: короче — это билет на пять дней или каток на сутки. */
    public const RUN_MIN_DAYS = 7;

    /**
     * Успеет ли пост, назначенный на этот момент, к своему событию.
     *
     * @param  int  $minLeadHours  запас, который просит автомат; 0 — «лишь бы не после»
     */
    public static function fits(?Event $event, Carbon $publishAt, int $minLeadHours = 0): bool
    {
        $deadline = self::deadline($event);

        if ($deadline === null) {
            return true; // событию нечего сказать о сроке — не выдумываем за него
        }

        return $publishAt->lte($deadline->copy()->subHours(max(0, $minLeadHours)));
    }

    /**
     * Самый ранний срок среди событий записи: у подборки их несколько, fits() ей не годится.
     *
     * @param  iterable<object>  $events  события записи: одно у поста, несколько у подборки, ноль у портрета
     */
    public static function earliestDeadline(iterable $events): ?Carbon
    {
        $earliest = null;

        foreach ($events as $event) {
            $deadline = self::deadline($event);
            if ($deadline === null) {
                continue;
            }
            if ($earliest === null || $deadline->lt($earliest)) {
                $earliest = $deadline;
            }
        }

        return $earliest;
    }

    /**
     * Успеет ли запись к своему составу.
     *
     * @param  iterable<object>  $events
     */
    public static function fitsAll(iterable $events, Carbon $publishAt, int $minLeadHours = 0): bool
    {
        $deadline = self::earliestDeadline($events);

        if ($deadline === null) {
            return true;
        }

        return $publishAt->lte($deadline->copy()->subHours(max(0, $minLeadHours)));
    }

    /**
     * Правило fits() в виде SQL-условия, правятся вместе. Отличие: событие
     * без start_time здесь проходит только с `$wholeDay`, в fits() проходит всегда.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $table  префикс колонок: 'events' или алиас вроде 'e'
     * @param  bool  $strictRuns  начавшееся проходит, только если это прокат от RUN_MIN_DAYS, а не сеанс с общим для серии концом
     * @param  bool  $wholeDay  событие без часа идёт весь свой день и проходит, пока день не кончился (подборкам)
     */
    public static function applyFits(
        $query,
        Carbon $publishAt,
        int $minLeadHours = 0,
        string $table = 'events',
        bool $strictRuns = false,
        bool $wholeDay = false,
    ): void {
        $from = $publishAt->copy()->addHours(max(0, $minLeadHours));

        $query->where(function ($w) use ($from, $table, $strictRuns, $wholeDay) {
            $w->where($table.'.start_time', '>=', $from)
                ->orWhere(function ($x) use ($from, $table, $strictRuns) {
                    $x->whereNotNull($table.'.end_time')
                        ->where($table.'.end_time', '>=', $from);

                    if (! $strictRuns) {
                        $x->whereRaw(
                            $table.'.end_time > '.$table.'.start_time + make_interval(hours => ?)',
                            [self::MULTI_DAY_HOURS],
                        );

                        return;
                    }

                    $x->whereRaw(
                        $table.'.end_time >= '.$table.'.start_time + make_interval(days => ?)',
                        [self::RUN_MIN_DAYS],
                    )
                        // у сеансов спектакля источник ставит конец всего проката, и прошедший сеанс выглядел бы идущим
                        ->whereNotExists(function ($s) use ($table) {
                            $s->selectRaw('1')
                                ->from('events as same_end')
                                ->whereColumn('same_end.event_group_id', $table.'.event_group_id')
                                ->whereColumn('same_end.id', '<>', $table.'.id')
                                ->whereColumn('same_end.end_time', $table.'.end_time')
                                ->whereNull('same_end.deleted_at');
                        });
                });

            if ($wholeDay) {
                $w->orWhere(fn ($x) => $x->whereNull($table.'.start_time')
                    ->where($table.'.start_date', '>=', $from->copy()->setTimezone('Europe/Moscow')->toDateString()));
            }
        });
    }

    /**
     * Закрытие многодневки, которая к этому моменту уже идёт; null — событие ещё не началось
     * или однодневное. От закрытия у такой считаются свежесть балла и дата в подписи.
     */
    public static function runningUntil(?Event $event, DateTimeInterface $at): ?Carbon
    {
        $deadline = self::deadline($event);
        if ($deadline === null) {
            return null;
        }

        $start = Carbon::parse($event->start_time);

        return $start->lt($at) && $deadline->gt($start) ? $deadline : null;
    }

    /** Последний момент, когда пост ещё имеет смысл. SQL-вариант в applyFits, правятся вместе. */
    public static function deadline(?Event $event): ?Carbon
    {
        if (! $event || ! $event->start_time) {
            return null;
        }

        $start = Carbon::parse($event->start_time);
        $end = $event->end_time ? Carbon::parse($event->end_time) : null;

        if ($end !== null && $end->gt($start->copy()->addHours(self::MULTI_DAY_HOURS))) {
            return $end;
        }

        return $start;
    }
}
