<?php

namespace App\Contracts\Telegram;

use App\Models\TelegramUser;
use App\Models\User;

interface TelegramUserRepositoryInterface
{
    public function findByTelegramId(int $telegramId): ?TelegramUser;

    public function getBoundUserByTelegramId(int $telegramId): ?User;
}
