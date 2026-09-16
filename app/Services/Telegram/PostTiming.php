<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use Carbon\Carbon;

/**
 * Одно правило на все двери: успевает ли пост к своему событию.
 *
 * ЗАЧЕМ ОТДЕЛЬНО. Дверей, через которые записи получают день, пять — подбор
 * кандидатов, наполнитель ленты, перетаскивание в админке, правка формы и
 * возврат из снятых, — и правило в них разошлось. Подбор требовал фору,
 * перетаскивание пускало пост до КОНЦА события, а возврат не проверял вообще
 * ничего. Итог виден владельцу: пост стоит в тот же день, что событие, но
 * позже него — доставка такую запись снимет, а слот сгорит молча.
 *
 * ДВА ПОРОГА, и разница между ними осознанная:
 *
 *  - «не позже начала» — жёсткий, действует даже на человека. Анонс после
 *    начала — это анонс задним числом, и звать на него некуда.
 *  - «за N часов до» — мягкий, для АВТОМАТА. Машина, поставившая пост за час
 *    до концерта, формально права, но человеку некогда собраться. Руками
 *    поставить впритык можно: раз человек это делает осознанно, значит у него
 *    есть причина.
 *
 * МНОГОДНЕВКИ (выставка, прокат спектакля) живут по другому правилу: у них
 * «успеть» — это успеть до закрытия, а не до открытия. Событие длиннее суток
 * считаем многодневным: у концерта end_time стоит на пару часов позже начала,
 * у выставки — на месяц.
 */
final class PostTiming
{
    /**
     * Сколько часов между постом и началом события просит АВТОМАТ.
     *
     * Шесть: пост утром про вечер того же дня ещё имеет смысл, пост за час до
     * начала — уже нет. Меньше суток и больше часа — это ровно тот диапазон,
     * где решение принимает читатель, а не мы.
     */
    public const MIN_LEAD_HOURS = 6;

    /** Дольше суток — значит не вечер, а прокат: успевать надо к закрытию. */
    public const MULTI_DAY_HOURS = 24;

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
     * Срок ЗАПИСИ, а не только события.
     *
     * У поста-подборки своего события нет, и все двери, спрашивающие
     * `fits($event, ...)`, пропускали её насквозь: `null` означает «событию
     * нечего сказать о сроке». Но подборке есть что сказать — она называет
     * три события, и её срок это самое раннее из них: начался первый — пост
     * уже рассказывает про прошлое.
     *
     * @param  iterable<object>  $events  события записи: одно у поста события,
     *                                    три у подборки, ноль у портрета площадки
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
     * Условие «успевает» для SQL-запросов.
     *
     * Двери, которые отбирают события запросом, не могут позвать fits() и до
     * сих пор писали правило руками — с зашитым числом 24 в двух местах и без
     * форы в третьем. Отдаём им ту же истину одним выражением.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $table  префикс колонок: 'events' или алиас вроде 'e'
     */
    public static function applyFits(
        $query,
        Carbon $publishAt,
        int $minLeadHours = 0,
        string $table = 'events',
    ): void {
        $from = $publishAt->copy()->addHours(max(0, $minLeadHours));

        $query->where(function ($w) use ($from, $table) {
            $w->where($table.'.start_time', '>=', $from)
                ->orWhere(function ($x) use ($from, $table) {
                    $x->whereNotNull($table.'.end_time')
                        ->whereRaw(
                            $table.'.end_time > '.$table.".start_time + make_interval(hours => ?)",
                            [self::MULTI_DAY_HOURS],
                        )
                        ->where($table.'.end_time', '>=', $from);
                });
        });
    }

    /**
     * Последний момент, когда пост ещё имеет смысл.
     *
     * Для события одного дня — его начало, для многодневки — конец проката.
     */
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
