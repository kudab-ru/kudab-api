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
     * @param int   $broadcastId
     * @param array $statuses  Список строк-статусов
     * @param int   $limit     Максимальное количество элементов
     *
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

}
