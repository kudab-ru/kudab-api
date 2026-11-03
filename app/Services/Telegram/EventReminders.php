<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use App\Services\EventChanges;
use App\Support\EventStart;
use App\Support\Telegram\VenueName;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «Пойду» на сайте и напоминание в боте: накануне вечером, у идущей выставки — накануне последнего дня.
 * Когда напомнить и что написать, решает API; бот забирает созревшие (`due`) и отчитывается (`mark`).
 */
final class EventReminders
{
    private const TZ = 'Europe/Moscow';

    private const TABLE = 'telegram.event_reminders';

    private const EVENING = 18;

    /** Вечер накануне уже прошёл — напоминаем утром в день события. */
    private const MORNING = 10;

    /** Бот забрал запись и не отчитался: через столько минут она снова уходит в выдачу. */
    private const LEASE_MINUTES = 10;

    private const WEEKDAYS = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];

    private const MONTHS = [1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'];

    public function __construct(private readonly EventChanges $changes) {}

    /**
     * Человек открыл бота по ссылке «напомнить» со страницы события. В `status` ответ: `scheduled`, `already`,
     * `soon` (начнётся раньше, чем успеем напомнить), `past`, `missing` или состояние отменённого события.
     *
     * @return array<string, mixed>
     */
    public function schedule(int $telegramId, int $chatId, int $eventId, Carbon $now): array
    {
        $now = $now->copy()->utc();
        $event = Event::query()->with('venue')->find($eventId);
        if ($event === null) {
            return ['status' => 'missing'];
        }
        $card = $this->card($event);
        if (in_array($event->status, EventChanges::KINDS, true)) {
            return ['status' => (string) $event->status, 'event' => $card];
        }
        $plan = $this->plan($event, $now);
        if ($plan === null) {
            return ['status' => $this->isOver($event, $now) ? 'past' : 'soon', 'event' => $card];
        }

        $row = DB::table(self::TABLE)->where('telegram_id', $telegramId)->where('event_id', $eventId)->first();
        if ($row !== null && $row->sent_at === null && $row->cancelled_at === null) {
            return ['status' => 'already', 'id' => (int) $row->id, 'kind' => $row->kind,
                'remind_at' => $this->iso(Carbon::parse($row->remind_at)), 'event' => $card];
        }

        $test = (int) config('services.bot.reminder_test_minutes', 0);
        $fields = [
            'chat_id' => $chatId,
            'kind' => $plan['kind'],
            'remind_at' => $test > 0 ? $now->copy()->addMinutes($test) : $plan['at'],
            'event_start' => EventStart::of($event)?->utc(),
            'taken_at' => null, 'sent_at' => null, 'cancelled_at' => null, 'error' => null,
            'updated_at' => $now,
        ];
        if ($row === null) {
            $id = (int) DB::table(self::TABLE)->insertGetId(
                ['telegram_id' => $telegramId, 'event_id' => $eventId, 'created_at' => $now] + $fields,
            );
        } else {
            DB::table(self::TABLE)->where('id', $row->id)->update($fields);
            $id = (int) $row->id;
        }

        return ['status' => 'scheduled', 'id' => $id, 'kind' => $plan['kind'],
            'remind_at' => $this->iso($plan['at']), 'test_minutes' => $test > 0 ? $test : null, 'event' => $card];
    }

    /** «Не напоминать» под ответом бота. false — такой записи у человека нет. */
    public function cancel(int $telegramId, int $id, Carbon $now): bool
    {
        $now = $now->copy()->utc();

        return DB::table(self::TABLE)->where('id', $id)->where('telegram_id', $telegramId)->whereNull('sent_at')
            ->update(['cancelled_at' => $now, 'updated_at' => $now]) > 0;
    }

    /**
     * Созревшие напоминания с готовыми данными для текста. Сначала помечаем записи взятыми,
     * чтобы при медленном ответе бота напоминание не ушло дважды.
     *
     * @return list<array<string, mixed>>
     */
    public function due(Carbon $now, int $limit = 20): array
    {
        $now = $now->copy()->utc();
        $rows = DB::transaction(function () use ($now, $limit) {
            $rows = DB::table(self::TABLE)
                ->whereNull('sent_at')->whereNull('cancelled_at')
                ->where('remind_at', '<=', $now)
                ->where(fn ($w) => $w->whereNull('taken_at')->orWhere('taken_at', '<', $now->copy()->subMinutes(self::LEASE_MINUTES)))
                ->orderBy('remind_at')->limit($limit)->lockForUpdate()->get();
            if ($rows->isNotEmpty()) {
                DB::table(self::TABLE)->whereIn('id', $rows->pluck('id'))->update(['taken_at' => $now, 'updated_at' => $now]);
            }

            return $rows;
        });

        $out = [];
        foreach ($rows as $row) {
            $item = $this->item($row, $now);
            if ($item !== null) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** Бот отправил или не смог. Временная ошибка возвращает запись в выдачу, постоянная (бота заблокировали) закрывает. */
    public function mark(int $id, bool $ok, bool $permanent, ?string $error, Carbon $now): void
    {
        $now = $now->copy()->utc();
        $fields = $ok || $permanent
            ? ['sent_at' => $now, 'error' => $ok ? null : mb_substr((string) $error, 0, 255)]
            : ['taken_at' => null, 'error' => mb_substr((string) $error, 0, 255)];
        DB::table(self::TABLE)->where('id', $id)->update($fields + ['updated_at' => $now]);
    }

    /**
     * Что отправить по взятой записи; null — отправлять нечего: запись закрыта или ждёт нового времени.
     *
     * @return array<string, mixed>|null
     */
    private function item(object $row, Carbon $now): ?array
    {
        $event = Event::query()->with('venue')->find($row->event_id);
        if ($event === null) {
            $this->mark((int) $row->id, false, true, 'событие убрали с сайта', $now);

            return null;
        }
        $base = ['id' => (int) $row->id, 'chat_id' => (int) $row->chat_id, 'event' => $this->card($event)];

        if ($event->status === EventChanges::MOVED) {
            return $base + ['kind' => 'moved', 'moved_to' => $this->movedTo($row, $now)];
        }
        if (in_array($event->status, EventChanges::KINDS, true)) {
            return $base + ['kind' => (string) $event->status];
        }

        // дату сдвинули на том же событии: напоминаем к новой, а не к старой
        $start = EventStart::of($event);
        if ($row->event_start !== null && $start !== null && ! $start->equalTo(Carbon::parse($row->event_start))) {
            $plan = $this->plan($event, $now);
            if ($plan === null) {
                $this->mark((int) $row->id, false, true, 'дату сдвинули, напоминать поздно', $now);

                return null;
            }
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'kind' => $plan['kind'], 'remind_at' => $plan['at'], 'event_start' => $start->copy()->utc(), 'taken_at' => null, 'updated_at' => $now,
            ]);

            return null;
        }

        $day = $row->kind === 'last' && $event->end_time !== null
            ? Carbon::parse($event->end_time)->setTimezone(self::TZ)
            : ($start ?? $now)->copy()->setTimezone(self::TZ);

        return $base + ['kind' => (string) $row->kind, 'day' => $this->dayWord($day, $now)];
    }

    /**
     * Перенесли: заводим напоминание к новому событию и сообщаем новую дату.
     *
     * @return array<string, mixed>|null новое событие или null, если его нет на сайте
     */
    private function movedTo(object $row, Carbon $now): ?array
    {
        $newId = $this->changes->movedToId((int) $row->event_id);
        $new = $newId === null ? null : Event::query()->with('venue')->find($newId);
        if ($new === null) {
            return null;
        }
        $plan = $this->plan($new, $now);
        $exists = DB::table(self::TABLE)->where('telegram_id', $row->telegram_id)->where('event_id', $new->id)->exists();
        if ($plan !== null && ! $exists) {
            DB::table(self::TABLE)->insert([
                'telegram_id' => $row->telegram_id, 'chat_id' => $row->chat_id, 'event_id' => $new->id,
                'kind' => $plan['kind'], 'remind_at' => $plan['at'], 'event_start' => EventStart::of($new)?->utc(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $this->card($new) + ['remind' => $plan !== null];
    }

    /**
     * Когда напомнить: накануне в 18:00; вечер уже прошёл — утром в день события, а событие до полудня —
     * за два часа. Выставка уже идёт — так же к её последнему дню. null — напомнить уже не успеть.
     *
     * @return array{kind: string, at: Carbon}|null
     */
    private function plan(Event $event, Carbon $now): ?array
    {
        $now = $now->copy()->setTimezone(self::TZ);
        $start = EventStart::of($event)?->setTimezone(self::TZ);
        if ($start === null) {
            return null;
        }
        if ($start->greaterThan($now)) {
            return $this->before($start, $this->hasHour($event), 'eve', $now);
        }
        $end = $event->end_time !== null ? Carbon::parse($event->end_time)->setTimezone(self::TZ) : null;
        if ($end !== null && $end->copy()->startOfDay()->greaterThan($now->copy()->startOfDay())) {
            return $this->before($end->copy()->startOfDay(), false, 'last', $now);
        }

        return null;
    }

    /** @return array{kind: string, at: Carbon}|null время в UTC, как всё в таблице */
    private function before(Carbon $day, bool $hasHour, string $kind, Carbon $now): ?array
    {
        $soon = $now->copy()->addMinutes(10);
        $eve = $day->copy()->startOfDay()->subDay()->setTime(self::EVENING, 0);
        if ($eve->greaterThan($soon)) {
            return ['kind' => $kind, 'at' => $eve->utc()];
        }
        $at = $day->copy()->startOfDay()->setTime(self::MORNING, 0);
        if ($hasHour && $day->hour < 12) {
            $at = $day->copy()->subHours(2);
        }

        return $at->greaterThan($soon) ? ['kind' => $kind, 'at' => $at->utc()] : null;
    }

    /** Событие с часом: у события без часа в базе полночь его дня. */
    private function hasHour(Event $event): bool
    {
        return $event->start_time !== null && $event->time_precision !== 'date';
    }

    private function isOver(Event $event, Carbon $now): bool
    {
        $start = EventStart::of($event)?->setTimezone(self::TZ);
        if ($start === null) {
            return true;
        }
        // у события без часа весь его день ещё впереди
        $edge = $this->hasHour($event) ? $start : $start->copy()->endOfDay();

        return $edge->lessThan($now);
    }

    /** «завтра», «сегодня» или «7 ноября», если напоминание пришло в другой день (на стенде — через минуту). */
    private function dayWord(Carbon $day, Carbon $now): string
    {
        $diff = (int) $now->copy()->setTimezone(self::TZ)->startOfDay()->diffInDays($day->copy()->startOfDay(), false);

        return match ($diff) {
            0 => 'сегодня',
            1 => 'завтра',
            default => $day->day.' '.self::MONTHS[$day->month],
        };
    }

    /** @return array{id: int, title: string, when: string, venue: string, url: string, ticket_url: string} */
    private function card(Event $event): array
    {
        $start = EventStart::of($event)?->setTimezone(self::TZ);
        $when = '';
        if ($start !== null) {
            $when = self::WEEKDAYS[$start->isoWeekday()].', '.$start->day.' '.self::MONTHS[$start->month];
            if ($this->hasHour($event)) {
                $when .= ', '.$start->format('H:i');
            }
        }
        $base = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');

        return [
            'id' => (int) $event->id,
            'title' => (string) $event->title,
            'when' => $when,
            'venue' => VenueName::label($event->venue?->name),
            'url' => $base.'/events/'.$event->id.'?utm_source=tg&utm_medium=reminder',
            'ticket_url' => (string) ($event->price_url ?? ''),
        ];
    }

    private function iso(Carbon $at): string
    {
        return $at->copy()->setTimezone(self::TZ)->toIso8601String();
    }
}
