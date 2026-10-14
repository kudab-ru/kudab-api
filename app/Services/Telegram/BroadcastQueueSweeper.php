<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Уборщик ленты: снимает записи, которые уже не выйдут, а запись удалённого события перевешивает
 * на выжившее. Освободившийся слот займёт ближайший прогон наполнителя. Запись, чей час прошёл,
 * не трогает: её судьбу решает доставка.
 */
final class BroadcastQueueSweeper
{
    public const SKIPPED = 'skipped';

    public const MOVED = 'moved';

    /** Выжившее могли потом слить ещё раз: идём по журналу склеек, пока не найдём живое. */
    private const SURVIVOR_STEPS = 5;

    public function __construct(
        private readonly TelegramChatBroadcastService $broadcasts,
        private readonly TelegramChatBroadcastItemRepositoryInterface $items,
    ) {}

    /**
     * @return array{checked: int, done: list<array{item_id: int, event_id: int|null, at: string|null, action: string, reason: string|null, to_event_id: int|null, via: string|null}>}
     */
    public function sweep(TelegramChatBroadcast $broadcast, Carbon $now): array
    {
        return DB::transaction(function () use ($broadcast, $now) {
            TelegramChatBroadcast::lockFeed((int) $broadcast->id);

            $items = TelegramChatBroadcastItem::query()
                ->where('broadcast_id', $broadcast->id)
                ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
                ->whereNull('posted_at')
                ->whereNull('claimed_at')
                ->where(function ($q) use ($now) {
                    $q->where(function ($w) {
                        $w->whereNull('publish_at')->whereIn('status', [
                            TelegramChatBroadcastItem::STATUS_PENDING,
                            TelegramChatBroadcastItem::STATUS_PLANNED,
                        ]);
                    })->orWhere(function ($w) use ($now) {
                        $w->where('publish_at', '>', $now)->whereIn('status', [
                            TelegramChatBroadcastItem::STATUS_PENDING,
                            TelegramChatBroadcastItem::STATUS_PLANNED,
                            TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                            TelegramChatBroadcastItem::STATUS_APPROVED,
                            TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                        ]);
                    });
                })
                ->orderByRaw('publish_at ASC NULLS LAST, id ASC')
                ->get();

            $done = [];
            foreach ($items as $item) {
                $row = $item->publish_at === null
                    ? $this->sweepWaiting($item, $now)
                    : $this->sweepScheduled($broadcast, $item, $now);
                if ($row !== null) {
                    $done[] = $row;
                }
            }

            return ['checked' => $items->count(), 'done' => $done];
        });
    }

    /** Запись без дня ждёт слота; ждать незачем, если события нет или оно уже началось. */
    private function sweepWaiting(TelegramChatBroadcastItem $item, Carbon $now): ?array
    {
        $event = $item->event_id ? Event::query()->find($item->event_id) : null;

        $reason = match (true) {
            $event === null => 'события больше нет — снято из очереди ожидания',
            ! PostTiming::fits($event, $now) => 'событие началось, пока запись ждала свободного дня',
            default => null,
        };

        return $reason === null ? null : $this->skip($item, $reason);
    }

    /**
     * Запись с днём. Успевает ли событие — `PostTiming::fits` без запаса, как у доставки:
     * с запасом уборщик снимал бы то, что подбор тут же ставит обратно.
     */
    private function sweepScheduled(TelegramChatBroadcast $broadcast, TelegramChatBroadcastItem $item, Carbon $now): ?array
    {
        $at = Carbon::parse($item->publish_at);
        $event = $item->event_id ? Event::query()->find($item->event_id) : null;

        if ($event === null) {
            return $this->moveToSurvivor($broadcast, $item, $now)
                ?? $this->skip($item, 'событие удалили после выбора — слот освобождён');
        }

        $reason = match (true) {
            $event->status !== 'active' => 'событие снято с публикации',
            $event->tickets_status === 'sold_out' => 'билеты распроданы',
            ! PostTiming::fits($event, $at) => 'время сдвинули, к слоту не успевает',
            default => null,
        };

        return $reason === null ? null : $this->skip($item, $reason);
    }

    /**
     * Перевесить запись с днём, чьё событие удалили, на выжившее. Зовут уборщик и доставка, обе под `lockFeed`.
     *
     * @return array{item_id: int, event_id: int|null, at: string|null, action: string, reason: string|null, to_event_id: int|null, via: string|null}|null null — перевешивать некуда, запись не тронута
     */
    public function moveToSurvivor(TelegramChatBroadcast $broadcast, TelegramChatBroadcastItem $item, Carbon $now): ?array
    {
        $survivor = $item->event_id ? $this->survivorOf((int) $item->event_id) : null;
        // доставка приходит, когда час записи уже наступил: выжившее должно успеть к самой отправке
        $at = Carbon::parse($item->publish_at)->max($now);

        return $survivor !== null && $this->canMoveTo($broadcast, $item, $survivor[0], $at)
            ? $this->moveTo($item, $survivor[0], $survivor[1], $now)
            : null;
    }

    /**
     * Живое событие, в которое слили удалённое. Без записи в журнале склеек — живое с тем же
     * `dedup_key` (ночная пересборка ключей ссылки не оставляет), затем см. `postHolderOf`.
     *
     * @return array{0: Event, 1: string}|null событие и откуда оно: link, dedup_key или source
     */
    private function survivorOf(int $eventId): ?array
    {
        $seen = [$eventId => true];
        $drops = [$eventId];

        for ($step = 0; $step < self::SURVIVOR_STEPS && $drops !== []; $step++) {
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

        $dead = Event::withTrashed()->find($eventId);
        $twin = $dead?->dedup_key ? Event::query()->where('dedup_key', $dead->dedup_key)->whereKeyNot($eventId)->first() : null;
        if ($twin !== null) {
            return [$twin, 'dedup_key'];
        }

        $holder = $dead ? $this->postHolderOf($dead) : null;

        return $holder ? [$holder, 'source'] : null;
    }

    /**
     * Журнал пишет только перекрёстная склейка. Остальные переносят источники удалённого на выжившее,
     * и его исходный пост оказывается у выжившего. Остались свои источники — удалили не склейкой.
     * День сверяем, потому что один пост бывает афишей на неделю.
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

    /**
     * Строки (канал, выжившее) не должно быть вовсе: уникальный индекс полный, его держат
     * и снятые, и отклонённые. Дальше выжившее проходит жёсткие фильтры подбора, где серия
     * и площадка с минутой самой записи не в счёт, и успевает к слоту.
     */
    private function canMoveTo(TelegramChatBroadcast $broadcast, TelegramChatBroadcastItem $item, Event $survivor, Carbon $at): bool
    {
        $chat = $broadcast->chat;
        if (! $chat instanceof TelegramChat || ! $chat->city_id) {
            return false;
        }

        $hasRow = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('event_id', $survivor->id)
            ->exists();

        return ! $hasRow
            && PostTiming::fits($survivor, $at)
            && $this->broadcasts->channelCandidates($chat, (int) $broadcast->id, (int) $item->id)
                ->whereKey($survivor->id)
                ->exists();
    }

    /**
     * День, закрепление и ручной текст остаются. В ручном тексте ссылку на удалённую карточку
     * меняем на выжившую: удалённую сайт отдаёт как 404. Связь «пост → события» перепишет
     * `TelegramChatBroadcastItemObserver`. Ручной выбор картинок сбрасываем: форма правки принимает
     * только картинки выжившей карточки.
     */
    private function moveTo(TelegramChatBroadcastItem $item, Event $survivor, string $via, Carbon $now): array
    {
        $from = (int) $item->event_id;

        $item->event_id = $survivor->id;
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            $item->caption = $item->caption === null
                ? null
                : preg_replace('#/events/'.$from.'(?!\d)#', '/events/'.$survivor->id, $item->caption);
        } else {
            // текст из шаблона собран про прежнюю карточку; пустой соберут заново лента в админке и доставка
            $item->caption = null;
            $item->caption_source = null;
        }

        if (is_array($item->photo_urls)) {
            $item->photo_urls = null;
            $item->forgetEdit(TelegramChatBroadcastItem::EDIT_PHOTOS);
        }

        $meta = (array) ($item->pick_meta ?? []);
        $meta['moved'] = [
            'by' => 'sweeper',
            'from_event_id' => $from,
            'via' => $via,
            'at' => $now->copy()->setTimezone(BroadcastSlotPlanner::TZ)->toIso8601String(),
        ];
        $item->pick_meta = $meta;
        $item->save();

        return $this->row($item, self::MOVED, null, $from, (int) $survivor->id, $via);
    }

    private function skip(TelegramChatBroadcastItem $item, string $reason): array
    {
        $this->items->markSkipped($item, $reason);

        return $this->row($item, self::SKIPPED, $reason, $item->event_id !== null ? (int) $item->event_id : null, null, null);
    }

    /** @return array{item_id: int, event_id: int|null, at: string|null, action: string, reason: string|null, to_event_id: int|null, via: string|null} */
    private function row(TelegramChatBroadcastItem $item, string $action, ?string $reason, ?int $eventId, ?int $toEventId, ?string $via): array
    {
        return [
            'item_id' => (int) $item->id,
            'event_id' => $eventId,
            'at' => $item->publish_at
                ? Carbon::parse($item->publish_at)->setTimezone(BroadcastSlotPlanner::TZ)->toIso8601String()
                : null,
            'action' => $action,
            'reason' => $reason,
            'to_event_id' => $toEventId,
            'via' => $via,
        ];
    }
}
