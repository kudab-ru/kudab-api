<?php

namespace App\Contracts\Telegram;

interface BotRoleServiceInterface
{
    /** guest|user|moderator|admin|superadmin */
    public function getRoleByTelegramId(int $telegramId): string;
}
