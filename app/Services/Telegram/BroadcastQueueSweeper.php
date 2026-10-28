<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\EventChanges;
use App\Services\EventSurvivor;
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

    public function __construct(
        private readonly TelegramChatBroadcastService $broadcasts,
        private readonly TelegramChatBroadcastItemRepositoryInterface $items,
        private readonly EventSurvivor $survivors,
        private readonly EventChanges $changes,
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
            $event->status !== 'active' => self::offReason((string) $event->status),
            ! PostTiming::fits($event, $now) => 'событие началось, пока запись ждала свободного дня',
            default => null,
        };

        return $reason === null ? null : $this->skip($item, $reason);
    }

    private static function offReason(string $status): string
    {
        return match ($status) {
            EventChanges::CANCELLED => 'событие отменили',
            EventChanges::POSTPONED => 'событие отложили, новой даты нет',
            EventChanges::MOVED => 'событие перенесли',
            default => 'событие снято с публикации',
        };
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

        // пост о перенесённом событии нужен и на новую дату: перевешиваем, как запись склеенного
        if ($event->status === EventChanges::MOVED) {
            $newId = $this->changes->movedToId((int) $event->id);
            $new = $newId !== null ? Event::query()->find($newId) : null;
            if ($new !== null && $this->canMoveTo($broadcast, $item, $new, $at->copy()->max($now))) {
                return $this->moveTo($item, $new, 'перенос', $now);
            }
        }

        $reason = match (true) {
            $event->status !== 'active' => self::offReason((string) $event->status),
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
        $survivor = $item->event_id ? $this->survivors->find((int) $item->event_id) : null;
        // доставка приходит, когда час записи уже наступил: выжившее должно успеть к самой отправке
        $at = Carbon::parse($item->publish_at)->max($now);

        return $survivor !== null && $this->canMoveTo($broadcast, $item, $survivor[0], $at)
            ? $this->moveTo($item, $survivor[0], $survivor[1], $now)
            : null;
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
