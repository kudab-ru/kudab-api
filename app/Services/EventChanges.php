<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Отменённое, отложенное и перенесённое событие: состояние ставит парсер в `events.status` и журнал
 * `event_changes`. Лента такие события не показывает, а страница события остаётся и говорит, что случилось.
 */
final class EventChanges
{
    public const CANCELLED = 'cancelled';

    public const POSTPONED = 'postponed';

    public const MOVED = 'moved';

    public const KINDS = [self::CANCELLED, self::POSTPONED, self::MOVED];

    /**
     * Для страницы события. null — событие идёт, как объявлено. `moved_to` — новое событие, если оно на сайте.
     *
     * @return array{kind: string, moved_to: array{id: int, start_at: ?string, start_date: ?string, time_precision: ?string}|null}|null
     */
    public function forPage(int $eventId, ?string $status): ?array
    {
        if (! in_array($status, self::KINDS, true)) {
            return null;
        }

        return ['kind' => $status, 'moved_to' => $status === self::MOVED ? $this->movedTo($eventId) : null];
    }

    /** Новое событие, куда перенесли это; null — не переносили или нового на сайте нет. */
    public function movedToId(int $eventId): ?int
    {
        return $this->movedTo($eventId)['id'] ?? null;
    }

    /** @return array{id: int, start_at: ?string, start_date: ?string, time_precision: ?string}|null */
    private function movedTo(int $eventId): ?array
    {
        // при выкате код встаёт раньше миграции, и таблицы ещё может не быть
        try {
            $new = DB::table('event_changes as c')
                ->join('events as e', 'e.id', '=', 'c.new_event_id')
                ->where('c.event_id', $eventId)
                ->where('c.kind', self::MOVED)
                ->whereNull('e.deleted_at')
                ->where('e.status', 'active')
                ->orderByDesc('c.id')
                ->first(['e.id', 'e.start_time', 'e.start_date', 'e.time_precision']);
        } catch (\Throwable) {
            return null;
        }
        if ($new === null) {
            return null;
        }

        return [
            'id' => (int) $new->id,
            'start_at' => $new->start_time !== null ? \Carbon\Carbon::parse($new->start_time)->setTimezone('Europe/Moscow')->toIso8601String() : null,
            'start_date' => $new->start_date !== null ? substr((string) $new->start_date, 0, 10) : null,
            'time_precision' => $new->time_precision,
        ];
    }
}
