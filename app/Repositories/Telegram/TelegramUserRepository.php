<?php

namespace App\Repositories\Telegram;

use App\Contracts\Telegram\TelegramUserRepositoryInterface;
use App\Models\TelegramUser;
use App\Models\User;

class TelegramUserRepository implements TelegramUserRepositoryInterface
{
    public function findByTelegramId(int $telegramId): ?TelegramUser
    {
        return TelegramUser::query()
            ->where('telegram_id', $telegramId)
            ->first();
    }

    public function getBoundUserByTelegramId(int $telegramId): ?User
    {
        $telegramUser = $this->findByTelegramId($telegramId);
        return $telegramUser?->user ?? null;
    }
}
