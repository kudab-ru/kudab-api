<?php

namespace App\Repositories\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->event_id = $eventId;

        if ($plannedAt) {
            $item->status = TelegramChatBroadcastItem::STATUS_PLANNED;
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
    public function enqueueForReview(
        int $broadcastId,
        int $eventId,
        int $reviewerTelegramId,
        DateTimeInterface $deadlineAt,
    ): TelegramChatBroadcastItem {
        $existing = $this->findByBroadcastAndEvent($broadcastId, $eventId);
        if ($existing) {
            return $existing;
        }

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->event_id = $eventId;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING_REVIEW;
        $item->review_reviewer_telegram_id = $reviewerTelegramId;
        $item->review_deadline_at = $deadlineAt;
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
        $item->status = TelegramChatBroadcastItem::STATUS_POSTED;
        $item->posted_at = $moment ?: now();
        // planned_at оставляем как есть (может пригодиться для анализа)
        $item->error_message = null;
        $item->claimed_at = null;
        $item->claim_token = null;
        $item->save();

        return $item->refresh();
    }

    /**
     * Атомарно клеймит publish-айтем на публикацию (time-lease).
     *
     * UPDATE … WHERE id AND status∈publishable AND (claimed_at IS NULL OR
     * claimed_at < now−lease). Возвращает claim_token при успехе (1 строка),
     * иначе null — айтем уже заклеймлен другим поллером в пределах lease.
     * Так параллельный поллер / повторный poll после краша не берёт айтем дважды.
     */
    public function claimForPublish(int $itemId, DateTimeInterface $now, int $leaseSeconds): ?string
    {
        $nowCarbon = $now instanceof Carbon ? $now->copy() : Carbon::instance($now);
        $cutoff = $nowCarbon->copy()->subSeconds(max(1, $leaseSeconds));
        $token = (string) Str::uuid();

        $affected = TelegramChatBroadcastItem::query()
            ->where('id', $itemId)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('claimed_at')->orWhere('claimed_at', '<', $cutoff);
            })
            ->update([
                'claimed_at' => $nowCarbon,
                'claim_token' => $token,
                'updated_at' => $nowCarbon,
            ]);

        return $affected === 1 ? $token : null;
    }

    /**
     * Помечает айтем posted ТОЛЬКО если claim_token совпадает (атомарно).
     *
     * Защита от stale-claim: если lease истёк и айтем реклеймил другой поллер,
     * наш токен не совпадёт → 0 строк → false (не двигаем last_run за чужой пост).
     * Возвращает true при успехе.
     */
    public function markPostedIfClaimed(int $itemId, string $claimToken, ?DateTimeInterface $moment = null): bool
    {
        $affected = TelegramChatBroadcastItem::query()
            ->where('id', $itemId)
            ->where('claim_token', $claimToken)
            // status-guard: помечаем posted только из публикуемого статуса — не
            // флипаем уже skipped/error/posted айтем, даже если токен совпал.
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->update([
                'status' => TelegramChatBroadcastItem::STATUS_POSTED,
                'posted_at' => $moment ?: now(),
                'error_message' => null,
                'claimed_at' => null,
                'claim_token' => null,
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * {@inheritdoc}
     */
    public function markSkipped(
        TelegramChatBroadcastItem $item,
        ?string $reason = null,
    ): TelegramChatBroadcastItem {
        $item->status = TelegramChatBroadcastItem::STATUS_SKIPPED;
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
        $item->status = TelegramChatBroadcastItem::STATUS_ERROR;
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

        if (! empty($statuses)) {
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

        if (! empty($statuses)) {
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

    /**
     * {@inheritdoc}
     */
    public function findActiveForBroadcast(int $broadcastId, DateTimeInterface $now): ?TelegramChatBroadcastItem
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->where(function ($q) use ($now) {
                // pending/planned ждут planned_at; ревью-статусы готовы сразу.
                $q->whereNotIn('status', [
                    TelegramChatBroadcastItem::STATUS_PENDING,
                    TelegramChatBroadcastItem::STATUS_PLANNED,
                ])
                    ->orWhereNull('planned_at')
                    ->orWhere('planned_at', '<=', $now);
            })
            ->orderByRaw('COALESCE(planned_at, created_at) ASC')
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $itemId): ?TelegramChatBroadcastItem
    {
        return TelegramChatBroadcastItem::query()->find($itemId);
    }

    /**
     * {@inheritdoc}
     */
    public function setReviewMessageId(TelegramChatBroadcastItem $item, int $messageId): TelegramChatBroadcastItem
    {
        $item->review_message_id = $messageId;
        $item->save();

        return $item->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function applyReviewDecision(
        TelegramChatBroadcastItem $item,
        string $newStatus,
        string $action,
        DateTimeInterface $now,
    ): bool {
        // Атомарный guard по status: если timeout-sweeper / другой запрос уже увёл item
        // из pending_review — наш UPDATE его не тронет (0 затронутых), решение не теряется.
        $affected = TelegramChatBroadcastItem::query()
            ->where('id', $item->id)
            ->where('status', TelegramChatBroadcastItem::STATUS_PENDING_REVIEW)
            ->update([
                'status' => $newStatus,
                'review_action' => $action,
                'reviewed_at' => $now,
            ]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function autoApproveExpiredReviews(DateTimeInterface $now): int
    {
        return TelegramChatBroadcastItem::query()
            ->where('status', TelegramChatBroadcastItem::STATUS_PENDING_REVIEW)
            ->whereNotNull('review_deadline_at')
            ->where('review_deadline_at', '<=', $now)
            ->update([
                'status' => TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                'review_action' => 'timeout',
                'reviewed_at' => $now,
            ]);
    }
}
