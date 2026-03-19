<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\BotRoleServiceInterface;
use App\Contracts\Telegram\TelegramChatRepositoryInterface;
use App\Contracts\Telegram\TelegramUserRepositoryInterface;
use App\Models\TelegramChat;
use Illuminate\Support\Collection;
use RuntimeException;

class TelegramChatService
{
    public function __construct(
        private readonly TelegramUserRepositoryInterface  $telegramUserRepository,
        private readonly TelegramChatRepositoryInterface $chatRepository,
        private readonly BotRoleServiceInterface          $botRoleService,
    ) {}

    /**
     * Список всех связанных чатов по telegram_id.
     * Если TelegramUser ещё не создан — вернётся пустая коллекция.
     */
    public function listChatsByTelegramId(int $telegramId): Collection
    {
        $telegramUser = $this->telegramUserRepository->findByTelegramId($telegramId);
        if (!$telegramUser) {
            return collect();
        }

        return $this->chatRepository->getByTelegramUserId($telegramUser->id);
    }

    /**
     * Привязать чат к пользователю (по telegram_id).
     */
    public function linkChat(
        int $telegramId,
        int $telegramChatId,
        string $chatType,
        ?string $title = null,
        ?string $username = null,
    ): TelegramChat {
        $this->assertCanManageChats($telegramId);

        $telegramUser = $this->telegramUserRepository->findByTelegramId($telegramId);
        if (!$telegramUser) {
            throw new RuntimeException('Telegram-пользователь не найден в БД');
        }

        return $this->chatRepository->linkChat(
            $telegramUser->id,
            $telegramChatId,
            $chatType,
            $title,
            $username,
        );
    }

    /**
     * Проверка, что у telegram_id достаточно прав управлять чатами.
     * Сейчас — только admin / superadmin.
     */
    private function assertCanManageChats(int $telegramId): void
    {
        $role = $this->botRoleService->getRoleByTelegramId($telegramId);

        // было: только admin / superadmin
        // if (!in_array($role, ['admin', 'superadmin'], true)) {

        // стало: user, moderator, admin, superadmin
        if (!in_array($role, ['user', 'moderator', 'admin', 'superadmin'], true)) {
            throw new RuntimeException('Недостаточно прав для управления связанными чатами');
        }
    }

    /**
     * Системный unlink по chat_id (когда бота выгнали из чата).
     * Возвращает коллекцию уже отвязанных TelegramChat.
     */
    public function forceUnlinkByChatId(int $telegramChatId): Collection
    {
        $chats = $this->chatRepository->getByTelegramChatId($telegramChatId, true);

        if ($chats->isEmpty()) {
            return $chats;
        }

        // map на Eloquent\Collection вернёт снова Eloquent\Collection
        return $chats->map(function (TelegramChat $chat) {
            return $this->chatRepository->unlinkChat($chat);
        });
    }

}
