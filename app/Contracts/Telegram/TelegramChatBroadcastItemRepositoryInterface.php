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
     * Поставить событие в очередь для данного broadcast'а.
     *
     * Если элемент для (broadcast_id, event_id) уже существует —
     * он возвращается как есть (без изменения статуса).
     *
     * plannedAt:
     *   - null  → элемент создаётся со статусом "pending";
     *   - !null → статус "planned" и planned_at = plannedAt.
     */
    public function enqueue(
        int $broadcastId,
        int $eventId,
        ?DateTimeInterface $plannedAt = null,
    ): TelegramChatBroadcastItem;

    /**
     * Поставить событие в очередь под ревью-гейт (P0.5): status=pending_review,
     * snapshot reviewer-telegram-id и дедлайн авто-постинга. Идемпотентно по
     * (broadcast_id, event_id) — существующий элемент возвращается как есть.
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
     * Сколько незакрытых записей у канала. Событийные и venue считаются
     * раздельно: у портретов площадок свой каденс, и общий счёт заблокировал
     * бы их при заполненной ленте.
     *
     * @param  'event'|'venue'|null  $kind
     */
    public function countOpenForBroadcast(int $broadcastId, ?string $kind = null): int;

    public function countForBroadcast(
        int $broadcastId,
        array $statuses,
    ): int;

    /**
     * Активный (в полёте) элемент канала: pending/planned/pending_review/approved/
     * auto_approved. pending/planned уважают planned_at; ревью-статусы готовы сразу.
     */
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
