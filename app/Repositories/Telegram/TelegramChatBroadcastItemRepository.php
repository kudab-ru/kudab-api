<?php

namespace App\Repositories\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;

class TelegramChatBroadcastItemRepository implements TelegramChatBroadcastItemRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function findByBroadcastAndEvent(
        int $broadcastId,
        int $eventId,
    ): ?TelegramChatBroadcastItem {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('event_id', $eventId)
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function existsForBroadcastAndEvent(
        int $broadcastId,
        int $eventId,
    ): bool {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('event_id', $eventId)
            ->exists();
    }

    /**
     * {@inheritdoc}
     */
    public function enqueue(
        int $broadcastId,
        int $eventId,
        ?DateTimeInterface $plannedAt = null,
    ): TelegramChatBroadcastItem {
        $existing = $this->findByBroadcastAndEvent($broadcastId, $eventId);
        if ($existing) {
            return $existing;
        }

        $item = new TelegramChatBroadcastItem();
        $item->broadcast_id = $broadcastId;
        $item->event_id     = $eventId;

        if ($plannedAt) {
            $item->status     = TelegramChatBroadcastItem::STATUS_PLANNED;
            $item->planned_at = $plannedAt;
        } else {
            $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        }

        $item->save();

        return $item->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function findNextPlannedForBroadcast(
        int $broadcastId,
        ?DateTimeInterface $before = null,
    ): ?TelegramChatBroadcastItem {
        $before = $before ?: now();

        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('status', TelegramChatBroadcastItem::STATUS_PLANNED)
            ->where('planned_at', '<=', $before)
            ->orderBy('planned_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function markPosted(
        TelegramChatBroadcastItem $item,
        ?DateTimeInterface $moment = null,
    ): TelegramChatBroadcastItem {
        $item->status    = TelegramChatBroadcastItem::STATUS_POSTED;
        $item->posted_at = $moment ?: now();
        // planned_at оставляем как есть (может пригодиться для анализа)
        $item->error_message = null;
        $item->save();

        return $item->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function markSkipped(
        TelegramChatBroadcastItem $item,
        ?string $reason = null,
    ): TelegramChatBroadcastItem {
        $item->status        = TelegramChatBroadcastItem::STATUS_SKIPPED;
        $item->error_message = $reason;
        $item->save();

        return $item->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function markError(
        TelegramChatBroadcastItem $item,
        string $errorMessage,
    ): TelegramChatBroadcastItem {
        $item->status        = TelegramChatBroadcastItem::STATUS_ERROR;
        $item->error_message = $errorMessage;
        $item->save();

        return $item->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function getLastPostedEventIdForBroadcast(int $broadcastId): ?int
    {
        $item = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->first();

        return $item?->event_id;
    }

    /**
     * {@inheritdoc}
     */
    public function listForBroadcast(
        int $broadcastId,
        array $statuses,
        int $limit,
    ): Collection {
        $query = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId);

        if (!empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        return $query
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function countForBroadcast(
        int $broadcastId,
        array $statuses,
    ): int {
        $query = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId);

        if (!empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        return (int) $query->count();
    }

    public function findNextDueForBroadcast(int $broadcastId, Carbon|DateTimeInterface $now): ?TelegramChatBroadcastItem
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
            ])
            ->where(function ($q) use ($now) {
                $q->whereNull('planned_at')
                    ->orWhere('planned_at', '<=', $now);
            })
            ->orderByRaw('COALESCE(planned_at, created_at) ASC')
            ->first();
    }
}
