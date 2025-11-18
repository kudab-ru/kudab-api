<?php

namespace App\Contracts\Telegram;

use App\Models\TelegramChat;
use Illuminate\Support\Collection;

interface TelegramChatRepositoryInterface
{
    /**
     * Найти чат по Telegram chat_id.
     */
    public function findByTelegramChatId(int $telegramChatId): ?TelegramChat;

    /**
     * Все чаты пользователя (по telegram_user_id).
     *
     * @param  int  $telegramUserId  ID из telegram.users
     * @param  bool $onlyActive      Только активные (is_active = true)
     */
    public function getByTelegramUserId(int $telegramUserId, bool $onlyActive = true): Collection;


    public function getByTelegramChatId(int $telegramChatId, bool $onlyActive = true): Collection;

    /**
     * Привязать (или перепривязать) чат к пользователю.
     * Если записи ещё нет — создаём, иначе обновляем.
     */
    public function linkChat(
        int $telegramUserId,
        int $telegramChatId,
        string $chatType,
        ?string $title = null,
        ?string $username = null,
    ): TelegramChat;

    /**
     * Отвязать чат: пометить неактивным и выставить unlinked_at.
     */
    public function unlinkChat(TelegramChat $chat): TelegramChat;
}
