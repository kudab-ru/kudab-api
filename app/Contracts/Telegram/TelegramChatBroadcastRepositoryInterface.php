<?php

namespace App\Contracts\Telegram;

use App\Models\TelegramChatBroadcast;
use DateTimeInterface;
use Illuminate\Support\Collection;

interface TelegramChatBroadcastRepositoryInterface
{
    /**
     * Найти настройки рассылки по chat_id (telegram.chats.id).
     */
    public function findByChatId(int $chatId): ?TelegramChatBroadcast;

    /**
     * Найти или создать настройки по chat_id.
     *
     * Если записи нет — создаём с дефолтами:
     *   enabled = false,
     *   settings = { period: "off", template_code: "basic" }.
     */
    public function getOrCreateByChatId(int $chatId): TelegramChatBroadcast;

    /**
     * Обновить настройки рассылки по chat_id.
     *
     * enabled — обязателен (переключатель "Рассылка: вкл/выкл").
     * period / templateCode — опциональны, если null — поле не меняем.
     */
    public function updateSettingsByChatId(
        int $chatId,
        bool $enabled,
        ?string $period = null,
        ?string $templateCode = null,
    ): TelegramChatBroadcast;

    /**
     * Обновить отметку последней фактической отправки (last_run_at).
     * Если moment не передан — используется now().
     */
    public function touchLastRunAt(
        int $chatId,
        ?DateTimeInterface $moment = null,
    ): void;

    /**
     * Обновить отметку последнего предпросмотра (last_preview_at).
     * Если moment не передан — используется now().
     */
    public function touchLastPreviewAt(
        int $chatId,
        ?DateTimeInterface $moment = null,
    ): void;

    /**
     * @return \Illuminate\Support\Collection<TelegramChatBroadcast>
     */
    public function listEnabledWithSchedule(): Collection;

}
