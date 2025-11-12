<?php

namespace App\Contracts\Telegram;

interface BotRoleServiceInterface
{
    /**
     * Вернуть глобальную роль для telegram_id.
     * guest|user|moderator|admin|superadmin
     */
    public function getRoleByTelegramId(int $telegramId): string;
}
