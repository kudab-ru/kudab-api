<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Support\Facades\DB;

/**
 * Живое событие, в которое слили удалённое. Склейки удаляют по-разному: перекрёстная пишет журнал,
 * чистки копий переносят источники на выжившее, пересборка ключей оставляет живого тёзку по ключу.
 */
final class EventSurvivor
{
    /** Выжившее могли потом слить ещё раз: идём по журналу склеек, пока не найдём живое. */
    private const STEPS = 5;

    /**
     * Сначала журнал склеек, без записи в нём — живое с тем же `dedup_key`, затем см. `postHolderOf`.
     *
     * @return array{0: Event, 1: string}|null событие и откуда оно: link, dedup_key или source
     */
    public function find(int $eventId): ?array
    {
        // у живого события выжившего нет, даже если сайт его прячет: двойник по ключу увёл бы не туда
        $dead = Event::onlyTrashed()->find($eventId);
        if ($dead === null) {
            return null;
        }

        $seen = [$eventId => true];
        $drops = [$eventId];

        for ($step = 0; $step < self::STEPS && $drops !== []; $step++) {
            $keeps = DB::table('event_duplicate_links')
                ->whereIn('drop_event_id', $drops)
                ->whereNotNull('merged_at')
                ->whereNull('reverted_at')
                ->orderByDesc('merged_at')
                ->orderByDesc('id')
                ->pluck('keep_event_id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => isset($seen[$id]))
                ->unique()
                ->values()
                ->all();
            if ($keeps === []) {
                break;
            }

            $alive = Event::query()->whereIn('id', $keeps)->get()->keyBy('id');
            foreach ($keeps as $id) {
                if ($alive->has($id)) {
                    return [$alive->get($id), 'link'];
                }
                $seen[$id] = true;
            }
            $drops = $keeps;
        }

        $twin = $dead->dedup_key ? Event::query()->where('dedup_key', $dead->dedup_key)->whereKeyNot($eventId)->first() : null;
        if ($twin !== null) {
            return [$twin, 'dedup_key'];
        }

        $holder = $this->postHolderOf($dead);

        return $holder ? [$holder, 'source'] : null;
    }

    /**
     * Чистки копий переносят источники удалённого на выжившее, и его исходный пост оказывается у
     * выжившего. Остались свои источники — удалили не склейкой. День сверяем, потому что один пост
     * бывает афишей на неделю.
     */
    private function postHolderOf(Event $dead): ?Event
    {
        if ($dead->original_post_id === null || $dead->start_date === null
            || EventSource::query()->where('event_id', $dead->id)->exists()) {
            return null;
        }

        $holders = Event::query()
            ->where('start_date', $dead->start_date->toDateString())
            ->whereIn('id', EventSource::query()->select('event_id')->where('context_post_id', $dead->original_post_id))
            ->limit(2)
            ->get();

        return $holders->count() === 1 ? $holders->first() : null;
    }
}
