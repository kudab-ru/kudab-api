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

    /** Портрет площадки вместо события; площадки нет — час заполнит «пока идёт». */
    public const PLACE = 'place';

    /** Под открытым небом сегодня вечером или завтра: для выходных днём, когда таких событий много. */
    public const OUT = 'out';

    /** Выставка или показ, который уже идёт и продлится ещё RUN_MIN_DAYS: пост не устареет, пока его читают. */
    public const RUN = 'run';

    /**
     * Запасная роль часа, для которого своего события не нашлось. В LABELS её нет: в сетку её не ставят.
     * Не нашлось и такого — час пропускается.
     */
    public const ANY = 'any';

    public const LABELS = [
        self::WALK => 'зайти сегодня',
        self::SIGN => 'записаться',
        self::STAGE => 'большая сцена заранее',
        self::BAR => 'бар или клуб',
        self::PLACE => 'место',
        self::OUT => 'на улице',
        self::RUN => 'пока идёт',
    ];

    /** Сколько дней после дня поста событие роли RUN должно ещё идти. */
    public const RUN_MIN_DAYS = 7;

    /** Роль => категории «как попасть» и срок в днях от дня поста по Москве: от и до. */
    private const RULES = [
        self::WALK => [[EventAccess::WALK, EventAccess::OUT], 0, 0],
        self::SIGN => [[EventAccess::SIGN], 2, 7],
        self::STAGE => [[EventAccess::STAGE], 5, 14],
        self::BAR => [[EventAccess::BAR], 2, 5],
        self::OUT => [[EventAccess::OUT], 0, 1],
        // у выставки с часом сегодняшний сеанс к посту уже начался, поэтому годится и завтрашний
        self::RUN => [[EventAccess::WALK, EventAccess::OUT], 0, 1],
        self::ANY => [[EventAccess::WALK, EventAccess::SIGN, EventAccess::BAR, EventAccess::STAGE, EventAccess::OUT], 2, 7],
    ];

    private const TZ = 'Europe/Moscow';

    /**
     * Подходит ли событие роли. Идущий прокат — «сегодня». Площадку, закрытую в день поста, «зайти» не зовёт.
     *
     * @param  list<int>  $closedWeekdays  дни недели 1–7, когда площадка закрыта
     * @param  Carbon|null  $lastDay  последний день показа, у выставки по дням — её последний сеанс; нужен роли RUN
     */
    public static function fits(
        string $role,
        string $access,
        Event $event,
        Carbon $publishAt,
        array $closedWeekdays = [],
        ?Carbon $lastDay = null,
    ): bool {
        [$accesses, $from, $to] = self::RULES[$role] ?? [[], 0, -1];
        if (! in_array($access, $accesses, true)) {
            return false;
        }

        $day = $publishAt->copy()->setTimezone(self::TZ)->startOfDay();
        if ($role === self::WALK && in_array($day->isoWeekday(), $closedWeekdays, true)) {
            return false;
        }
        if ($role === self::RUN) {
            $until = $lastDay ?? PostTiming::deadline($event);
            if ($until === null || $until->copy()->setTimezone(self::TZ)->startOfDay()->lt($day->copy()->addDays(self::RUN_MIN_DAYS))) {
                return false;
            }
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

    /**
     * Самый ранний день, который роль может позвать; null — роль берёт и уже идущее. Без нижней границы
     * ближние события съели бы предел выборки, и сцена через неделю до неё не дошла бы.
     */
    public static function firstDay(string $role, Carbon $publishAt): ?Carbon
    {
        $from = self::RULES[$role][1] ?? 0;

        return $from <= 0 ? null : $publishAt->copy()->setTimezone(self::TZ)->startOfDay()->addDays($from);
    }
}
