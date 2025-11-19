<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Contracts\Telegram\TelegramChatRepositoryInterface;
use App\Contracts\Telegram\TelegramUserRepositoryInterface;
use App\Contracts\Telegram\BotRoleServiceInterface;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use DateTimeInterface;
use RuntimeException;

class TelegramChatBroadcastService
{
    public function __construct(
        private readonly TelegramUserRepositoryInterface          $telegramUserRepository,
        private readonly TelegramChatRepositoryInterface          $chatRepository,
        private readonly TelegramChatBroadcastRepositoryInterface $broadcastRepository,
        private readonly BotRoleServiceInterface                   $botRoleService,
    ) {}

    /**
     * Получить (или создать) настройки рассылки по telegram_id и telegram_chat_id.
     *
     * Проверяем:
     *  - что TelegramUser существует,
     *  - что чат существует,
     *  - что у пользователя есть права управлять чатами,
     *  - что этот пользователь действительно владелец чата (или хотя бы админ, если так решишь).
     */
    public function getSettingsByTelegram(
        int $telegramId,
        int $telegramChatId,
    ): TelegramChatBroadcast {
        [$telegramChat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        return $this->broadcastRepository->getOrCreateByChatId($telegramChat->id);
    }

    /**
     * Обновить настройки рассылки (enabled/period/template) по telegram_id и telegram_chat_id.
     *
     * period / templateCode можно передавать частично:
     *  - если null — поле не меняем.
     */
    public function updateSettingsByTelegram(
        int $telegramId,
        int $telegramChatId,
        bool $enabled,
        ?string $period = null,
        ?string $templateCode = null,
    ): TelegramChatBroadcast {
        [$telegramChat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        return $this->broadcastRepository->updateSettingsByChatId(
            $telegramChat->id,
            $enabled,
            $period,
            $templateCode,
        );
    }

    /**
     * Отметить, что по этому чату только что была реальная отправка рассылки.
     * Предполагается использование из планировщика.
     */
    public function markRunExecutedForChatId(
        int $chatId,
        ?DateTimeInterface $moment = null,
    ): void {
        $this->broadcastRepository->touchLastRunAt($chatId, $moment);
    }

    /**
     * Отметить, что по этому чату только что был предпросмотр (например, в личку).
     */
    public function markPreviewExecutedForChatId(
        int $chatId,
        ?DateTimeInterface $moment = null,
    ): void {
        $this->broadcastRepository->touchLastPreviewAt($chatId, $moment);
    }

    // ---------------------------------------------------------------------
    // Внутренние помощники
    // ---------------------------------------------------------------------

    /**
     * Проверка прав + поиск чата, которым можно управлять.
     *
     * Возвращает кортеж [TelegramChat, роль].
     */
    private function resolveManagedChat(
        int $telegramId,
        int $telegramChatId,
    ): array {
        $role = $this->botRoleService->getRoleByTelegramId($telegramId);

        // Логика как в TelegramChatService: кто вообще может управлять чатами
        if (!in_array($role, ['user', 'moderator', 'admin', 'superadmin'], true)) {
            throw new RuntimeException('Недостаточно прав для управления связанными чатами');
        }

        $telegramUser = $this->telegramUserRepository->findByTelegramId($telegramId);
        if (!$telegramUser) {
            throw new RuntimeException('Telegram-пользователь не найден в БД');
        }

        $telegramChat = $this->chatRepository->findByTelegramChatId($telegramChatId);
        if (!$telegramChat) {
            throw new RuntimeException('Чат не найден в БД: ' . $telegramChatId);
        }

        // Базовое ограничение: чат должен принадлежать этому пользователю.
        // Если захочешь дать супер/админу управлять любым чатам — можно ослабить эту проверку.
        if ($telegramChat->telegram_user_id !== $telegramUser->id) {
            // Разрешаем superadmin/admin управлять любыми чатами (опционально — можно убрать).
            if (!in_array($role, ['admin', 'superadmin'], true)) {
                throw new RuntimeException('Этот чат не привязан к текущему пользователю');
            }
        }

        return [$telegramChat, $role];
    }
}
