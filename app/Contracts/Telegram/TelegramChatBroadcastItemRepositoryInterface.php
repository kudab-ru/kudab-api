<?php

namespace App\Contracts\Telegram;

use App\Models\TelegramChatBroadcastItem;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Репозиторий очереди публикаций в Telegram-чаты.
 *
 * Работает с моделью TelegramChatBroadcastItem:
 *   telegram.chat_broadcast_items
 */
interface TelegramChatBroadcastItemRepositoryInterface
{
    /**
     * Найти элемент очереди по (broadcast_id, event_id).
     */
    public function findByBroadcastAndEvent(
        int $broadcastId,
        int $eventId,
    ): ?TelegramChatBroadcastItem;

    /**
     * Проверить, есть ли уже элемент очереди для (broadcast_id, event_id)
     * в любом статусе (pending/planned/posted/…).
     */
    public function existsForBroadcastAndEvent(
        int $broadcastId,
        int $eventId,
    ): bool;

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
    ): TelegramChatBroadcastItem;

    /**
     * Найти следующий элемент для отправки для конкретного broadcast'а.
     *
     * Логика v1:
     *   - статус = planned;
     *   - planned_at <= $before (обычно now());
     *   - сортировка по planned_at ASC, затем id ASC.
     */
    public function findNextPlannedForBroadcast(
        int $broadcastId,
        ?DateTimeInterface $before = null,
    ): ?TelegramChatBroadcastItem;

    /**
     * Отметить элемент как успешно отправленный.
     * posted_at, status.
     */
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

    /**
     * Отметить элемент как пропущенный (например, дубль или устарело).
     * status = skipped, error_message (опционально — причина).
     */
    public function markSkipped(
        TelegramChatBroadcastItem $item,
        ?string $reason = null,
    ): TelegramChatBroadcastItem;

    /**
     * Отметить, что при отправке произошла ошибка.
     * status = error, error_message.
     */
    public function markError(
        TelegramChatBroadcastItem $item,
        string $errorMessage,
    ): TelegramChatBroadcastItem;

    /**
     * Вернуть id последнего успешно опубликованного события для данного broadcast'а,
     * если такой есть (status = posted, по posted_at).
     */
    public function getLastPostedEventIdForBroadcast(int $broadcastId): ?int;

    /**
     * Список элементов очереди для одного broadcast'а
     * по заданным статусам (обычно pending/planned).
     *
     * @param  array  $statuses  Список строк-статусов
     * @param  int  $limit  Максимальное количество элементов
     * @return Collection<int, TelegramChatBroadcastItem>
     */
    public function listForBroadcast(
        int $broadcastId,
        array $statuses,
        int $limit,
    ): Collection;

    /**
     * Количество элементов очереди для одного broadcast'а
     * по заданным статусам.
     */
    public function countForBroadcast(
        int $broadcastId,
        array $statuses,
    ): int;

    public function findNextDueForBroadcast(
        int $broadcastId,
        DateTimeInterface $now,
    ): ?TelegramChatBroadcastItem;

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
