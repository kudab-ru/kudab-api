<?php

namespace App\Contracts\Telegram;

use App\Models\TelegramChatBroadcastItem;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Репозиторий очереди публикаций в Telegram-чаты.
 */
interface TelegramChatBroadcastItemRepositoryInterface
{
    public function findByBroadcastAndEvent(
        int $broadcastId,
        int $eventId,
    ): ?TelegramChatBroadcastItem;


    /**
     * Если событийная запись (broadcast_id, event_id) уже есть, она возвращается как есть.
     * plannedAt null — статус pending, иначе planned с planned_at = plannedAt.
     */
    public function enqueue(
        int $broadcastId,
        int $eventId,
        ?DateTimeInterface $plannedAt = null,
    ): TelegramChatBroadcastItem;

    /**
     * Идемпотентен, как enqueue(); запись встаёт в pending_review с ревьюером
     * и дедлайном авто-одобрения.
     */
    public function enqueueForReview(
        int $broadcastId,
        int $eventId,
        int $reviewerTelegramId,
        DateTimeInterface $deadlineAt,
        ?DateTimeInterface $plannedAt = null,
    ): TelegramChatBroadcastItem;

    public function findNextPlannedForBroadcast(
        int $broadcastId,
        ?DateTimeInterface $before = null,
    ): ?TelegramChatBroadcastItem;

    public function markPosted(
        TelegramChatBroadcastItem $item,
        ?DateTimeInterface $moment = null,
    ): TelegramChatBroadcastItem;

    /**
     * Атомарно заклеймить publish-айтем на публикацию (time-lease).
     * Возвращает claim_token при успехе, null — если уже заклеймлен в пределах lease.
     */
    public function claimForPublish(int $itemId, DateTimeInterface $now, int $leaseSeconds): ?string;

    /**
     * Пометить posted только при совпадении claim_token (защита от stale-claim).
     * Возвращает true при успехе (1 строка).
     */
    public function markPostedIfClaimed(int $itemId, string $claimToken, ?DateTimeInterface $moment = null): bool;

    public function markSkipped(
        TelegramChatBroadcastItem $item,
        ?string $reason = null,
    ): TelegramChatBroadcastItem;

    public function markError(
        TelegramChatBroadcastItem $item,
        string $errorMessage,
    ): TelegramChatBroadcastItem;


    /**
     * @return Collection<int, TelegramChatBroadcastItem>
     */
    public function listForBroadcast(
        int $broadcastId,
        array $statuses,
        int $limit,
    ): Collection;

    /**
     * Сколько незакрытых записей у канала; null — всех видов.
     *
     * @param  'event'|'venue'|null  $kind
     */
    public function countOpenForBroadcast(int $broadcastId, ?string $kind = null): int;

    public function countForBroadcast(
        int $broadcastId,
        array $statuses,
    ): int;

    /** Следующая открытая запись канала, до которой дошла очередь. */
    public function findActiveForBroadcast(
        int $broadcastId,
        DateTimeInterface $now,
    ): ?TelegramChatBroadcastItem;

    public function findById(int $itemId): ?TelegramChatBroadcastItem;

    public function setReviewMessageId(
        TelegramChatBroadcastItem $item,
        int $messageId,
    ): TelegramChatBroadcastItem;

    /**
     * Применить решение ревью атомарно (guard WHERE status=pending_review):
     * status (approved/rejected) + reviewed_at + review_action.
     *
     * @return bool true если переход состоялся (item был ещё pending_review).
     */
    public function applyReviewDecision(
        TelegramChatBroadcastItem $item,
        string $newStatus,
        string $action,
        DateTimeInterface $now,
    ): bool;

    /**
     * Авто-одобрить просроченные pending_review (review_deadline_at <= now).
     *
     * @return int число затронутых элементов
     */
    public function autoApproveExpiredReviews(DateTimeInterface $now): int;
}
