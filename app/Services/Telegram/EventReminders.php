<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use App\Services\EventChanges;
use App\Support\EventStart;
use App\Support\TicketOffices;
use App\Support\Telegram\VenueAddress;
use App\Support\Telegram\VenueName;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «Пойду» на сайте и напоминание в боте: накануне вечером, у идущей выставки — накануне последнего дня,
 * по просьбе ещё и за три часа до начала. Когда напомнить и что написать, решает API;
 * бот забирает созревшие (`due`) и отчитывается (`mark`).
 */
final class EventReminders
{
    private const TZ = 'Europe/Moscow';

    private const TABLE = 'telegram.event_reminders';

    private const EVENING = 18;

    /** Вечер накануне уже прошёл — напоминаем утром в день события. */
    private const MORNING = 10;

    /** Второе напоминание, `soon`: за столько часов до начала. */
    private const SOON_HOURS = 3;

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

        $rows = DB::table(self::TABLE)->where('telegram_id', $telegramId)->where('event_id', $eventId)->get()->keyBy('kind');
        $row = $rows->get('eve') ?? $rows->get('last');
        $soonRow = $rows->get('soon');
        $soonSet = $soonRow !== null && $soonRow->sent_at === null && $soonRow->cancelled_at === null;
        $soonOffer = ! $soonSet && $this->soonAt($event, $now, $plan['at']) !== null;

        if ($row !== null && $row->sent_at === null && $row->cancelled_at === null) {
            return ['status' => 'already', 'id' => (int) $row->id, 'kind' => $row->kind, 'at_eve' => $plan['eve'],
                'remind_at' => $this->iso(Carbon::parse($row->remind_at)), 'soon_offer' => $soonOffer, 'soon_set' => $soonSet,
                'event' => $card];
        }

        $test = $this->testMinutes();
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

        return ['status' => 'scheduled', 'id' => $id, 'kind' => $plan['kind'], 'at_eve' => $plan['eve'],
            'remind_at' => $this->iso($plan['at']), 'soon_offer' => $soonOffer, 'soon_set' => $soonSet,
            'test_minutes' => $test > 0 ? $test : null, 'event' => $card];
    }

    /**
     * «Ещё и за 3 часа до начала» под ответом бота: второе напоминание к тому же событию.
     *
     * @return array{status: string, remind_at?: string}
     */
    public function addSoon(int $telegramId, int $id, Carbon $now): array
    {
        $now = $now->copy()->utc();
        $main = DB::table(self::TABLE)->where('id', $id)->where('telegram_id', $telegramId)->first();
        $event = $main === null ? null : Event::query()->find($main->event_id);
        if ($event === null) {
            return ['status' => 'missing'];
        }
        $at = $this->soonAt($event, $now, null);
        if ($at === null) {
            return ['status' => 'late'];
        }
        $test = $this->testMinutes();
        DB::table(self::TABLE)->updateOrInsert(
            ['telegram_id' => $telegramId, 'event_id' => $event->id, 'kind' => 'soon'],
            [
                'chat_id' => $main->chat_id,
                'remind_at' => $test > 0 ? $now->copy()->addMinutes(2 * $test) : $at,
                'event_start' => EventStart::of($event)?->utc(),
                'taken_at' => null, 'sent_at' => null, 'cancelled_at' => null, 'error' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
        );

        return ['status' => 'scheduled', 'remind_at' => $this->iso($at)];
    }

    /** «Не напоминать»: снимает все напоминания человека к этому событию. false — такой записи у него нет. */
    public function cancel(int $telegramId, int $id, Carbon $now): bool
    {
        $now = $now->copy()->utc();
        $eventId = DB::table(self::TABLE)->where('id', $id)->where('telegram_id', $telegramId)->value('event_id');
        if ($eventId === null) {
            return false;
        }
        DB::table(self::TABLE)->where('telegram_id', $telegramId)->where('event_id', $eventId)->whereNull('sent_at')
            ->update(['cancelled_at' => $now, 'updated_at' => $now]);

        return true;
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

        if (in_array($event->status, EventChanges::KINDS, true)) {
            // о переносе и отмене пишем одно сообщение, второе напоминание того же события молча закрываем
            if ($row->kind === 'soon') {
                $this->mark((int) $row->id, false, true, 'событие '.$event->status, $now);

                return null;
            }

            return $event->status === EventChanges::MOVED
                ? $base + ['kind' => 'moved', 'moved_to' => $this->movedTo($row, $now)]
                : $base + ['kind' => (string) $event->status];
        }

        // дату сдвинули на том же событии: напоминаем к новой, а не к старой
        $start = EventStart::of($event);
        if ($row->event_start !== null && $start !== null && ! $start->equalTo(Carbon::parse($row->event_start))) {
            $plan = $row->kind === 'soon' ? $this->soonAt($event, $now, null) : ($this->plan($event, $now)['at'] ?? null);
            if ($plan === null) {
                $this->mark((int) $row->id, false, true, 'дату сдвинули, напоминать поздно', $now);

                return null;
            }
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'remind_at' => $plan, 'event_start' => $start->copy()->utc(), 'taken_at' => null, 'updated_at' => $now,
            ]);

            return null;
        }

        $msk = ($start ?? $now)->copy()->setTimezone(self::TZ);
        $day = $row->kind === 'last' && $event->end_time !== null ? Carbon::parse($event->end_time)->setTimezone(self::TZ) : $msk;

        return $base + [
            'kind' => (string) $row->kind,
            'day' => $this->dayWord($day, $now),
            'time' => $this->hasHour($event) && $row->kind !== 'last' ? $msk->format('H:i') : '',
            'hours' => $row->kind === 'soon' ? max(1, (int) round($now->diffInMinutes($msk, false) / 60)) : null,
        ];
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
     * @return array{kind: string, at: Carbon, eve: bool}|null время в UTC, как всё в таблице; `eve` — напомним накануне
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

    /** @return array{kind: string, at: Carbon, eve: bool}|null */
    private function before(Carbon $day, bool $hasHour, string $kind, Carbon $now): ?array
    {
        $soon = $now->copy()->addMinutes(10);
        $eve = $day->copy()->startOfDay()->subDay()->setTime(self::EVENING, 0);
        if ($eve->greaterThan($soon)) {
            return ['kind' => $kind, 'at' => $eve->utc(), 'eve' => true];
        }
        $at = $day->copy()->startOfDay()->setTime(self::MORNING, 0);
        if ($hasHour && $day->hour < 12) {
            $at = $day->copy()->subHours(2);
        }

        return $at->greaterThan($soon) ? ['kind' => $kind, 'at' => $at->utc(), 'eve' => false] : null;
    }

    /** Время второго напоминания за три часа; null — у события нет часа или оно уже ближе, чем `$after`. */
    private function soonAt(Event $event, Carbon $now, ?Carbon $after): ?Carbon
    {
        $start = EventStart::of($event);
        if ($start === null || ! $this->hasHour($event)) {
            return null;
        }
        $at = $start->copy()->subHours(self::SOON_HOURS)->utc();
        if (! $at->greaterThan($now->copy()->addMinutes(10)) || ($after !== null && ! $at->greaterThan($after))) {
            return null;
        }

        return $at;
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

    private function testMinutes(): int
    {
        return (int) config('services.bot.reminder_test_minutes', 0);
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

    /** @return array<string, mixed> */
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
        $venue = $event->venue;
        // «МТС Live Холл Воронеж (ex. Event Hall)»: прежнее имя в скобках в короткой строке напоминания лишнее
        $name = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', VenueName::label($venue?->name)));
        // картинку превью сайт кладёт в кэш на месяц: метка меняется вместе с тем, что на ней нарисовано
        $stamp = substr(md5(implode('|', [$event->title, $event->start_time, $event->start_date, $event->venue_id, $event->price_min])), 0, 8);

        return [
            'id' => (int) $event->id,
            'title' => (string) $event->title,
            'when' => $when,
            'venue' => $name,
            'address' => $venue !== null ? VenueAddress::short($venue) : '',
            'route_url' => $venue !== null ? VenueAddress::routeUrl($venue) : '',
            'photo_url' => $base.'/og/event/'.$event->id.'/preview.jpg?c='.$stamp,
            'url' => $base.'/events/'.$event->id.'?utm_source=tg&utm_medium=reminder',
            'ticket_url' => TicketOffices::url($event->price_url, $event->external_url),
        ];
    }

    private function iso(Carbon $at): string
    {
        return $at->copy()->setTimezone(self::TZ)->toIso8601String();
    }
}
