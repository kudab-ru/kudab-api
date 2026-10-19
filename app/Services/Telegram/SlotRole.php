<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use App\Services\Telegram\Scoring\EventAccess;
use App\Support\EventStart;
use Carbon\Carbon;

/**
 * Роль часа сетки: какую категорию «как попасть» и на какой срок автомат ставит в этот час.
 * Роли задаются в настройках канала (`slot_roles`); у часа без роли выбор прежний.
 */
final class SlotRole
{
    public const WALK = 'walk';

    public const SIGN = 'sign';

    public const STAGE = 'stage';

    public const BAR = 'bar';

    /** Портрет площадки вместо события; площадки нет — час заполнит «зайти сегодня». */
    public const PLACE = 'place';

    public const LABELS = [
        self::WALK => 'зайти сегодня',
        self::SIGN => 'записаться',
        self::STAGE => 'большая сцена заранее',
        self::BAR => 'бар или клуб',
        self::PLACE => 'место',
    ];

    /** Роль => категории «как попасть» и срок в днях от дня поста по Москве: от и до. */
    private const RULES = [
        self::WALK => [[EventAccess::WALK, EventAccess::OUT], 0, 0],
        self::SIGN => [[EventAccess::SIGN], 1, 3],
        self::STAGE => [[EventAccess::STAGE], 2, 7],
        self::BAR => [[EventAccess::BAR], 0, 1],
    ];

    private const TZ = 'Europe/Moscow';

    /**
     * Подходит ли событие роли. Идущий прокат — «сегодня». Площадку, закрытую в день поста, «зайти» не зовёт.
     *
     * @param  list<int>  $closedWeekdays  дни недели 1–7, когда площадка закрыта
     */
    public static function fits(string $role, string $access, Event $event, Carbon $publishAt, array $closedWeekdays = []): bool
    {
        [$accesses, $from, $to] = self::RULES[$role] ?? [[], 0, -1];
        if (! in_array($access, $accesses, true)) {
            return false;
        }

        $day = $publishAt->copy()->setTimezone(self::TZ)->startOfDay();
        if ($role === self::WALK && in_array($day->isoWeekday(), $closedWeekdays, true)) {
            return false;
        }

        $start = EventStart::of($event);
        if ($start === null) {
            return false;
        }
        $lead = PostTiming::runningUntil($event, $publishAt) !== null
            ? 0
            : (int) $day->diffInDays($start->copy()->setTimezone(self::TZ)->startOfDay(), false);

        return $lead >= $from && $lead <= $to;
    }

    /** Самый дальний день, который роль может позвать: дальше выборке смотреть незачем. */
    public static function lastDay(string $role, Carbon $publishAt): Carbon
    {
        $to = self::RULES[$role][2] ?? 0;

        return $publishAt->copy()->setTimezone(self::TZ)->startOfDay()->addDays($to)->endOfDay();
    }
}
