<?php

namespace App\Repositories\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            // только событийные: ищем запись очереди, а «показывал ли канал событие»
            // спрашивать у связи «пост → события»
            ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->first();
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

        // в транзакции вместе со связью «пост → события», которую пишет обсервер на save:
        // недостачу связи видно только в broadcast:links:backfill --check
        return DB::transaction(function () use ($broadcastId, $eventId, $plannedAt) {
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
        });
    }

    /**
     * {@inheritdoc}
     */
    public function enqueueForReview(
        int $broadcastId,
        int $eventId,
        int $reviewerTelegramId,
        DateTimeInterface $deadlineAt,
        ?DateTimeInterface $plannedAt = null,
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
        // придержка на время генерации текста: превью ревьюеру должно уйти уже с ним
        $item->planned_at = $plannedAt;
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
     * {@inheritdoc}
     *
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
     * {@inheritdoc}
     */
    public function markPostedIfClaimed(int $itemId, string $claimToken, ?DateTimeInterface $moment = null): bool
    {
        $affected = TelegramChatBroadcastItem::query()
            ->where('id', $itemId)
            ->where('claim_token', $claimToken)
            // и при своём токене закрытую запись (skipped, error, posted) не трогаем
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
    public function countOpenForBroadcast(int $broadcastId, ?string $kind = null): int
    {
        $q = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ]);

        if ($kind === TelegramChatBroadcastItem::KIND_VENUE) {
            $q->where('kind', TelegramChatBroadcastItem::KIND_VENUE);
        } elseif ($kind === 'event') {
            // явный kind, а не «не venue»: иначе подборка займёт ячейку feed_limit
            $q->where('kind', TelegramChatBroadcastItem::KIND_EVENT);
        }

        return (int) $q->count();
    }

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
            // publish_at — назначенный день; pending_review выпускаем раньше,
            // чтобы превью пришло рецензенту до публикации
            ->where(function ($q) use ($now) {
                // Запись вне очереди без дня — черновик: уходит только по кнопке, не в суточное окно.
                $q->where(fn ($w) => $w->whereNull('publish_at')->where('is_off_grid', false))
                    ->orWhere('publish_at', '<=', $now)
                    ->orWhere('status', TelegramChatBroadcastItem::STATUS_PENDING_REVIEW);
            })
            // сначала записи с publish_at: иначе пост без дня по старому created_at
            // обгонит занявшего его день; id последним, created_at точен до секунды
            ->orderByRaw('(publish_at IS NULL) ASC, COALESCE(publish_at, planned_at, created_at) ASC, id ASC')
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
        // запись могли увести из pending_review sweeper по таймауту или другой запрос
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
