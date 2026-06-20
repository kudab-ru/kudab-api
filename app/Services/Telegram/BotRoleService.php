<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\BotRoleServiceInterface;
use App\Contracts\Telegram\TelegramUserRepositoryInterface;

class BotRoleService implements BotRoleServiceInterface
{
    public function __construct(
        private readonly TelegramUserRepositoryInterface $telegramUserRepository,
        private readonly SuperAdminProvisioner $superAdminProvisioner,
    ) {}

    /** Порядок приоритета (чем выше число — тем выше права) */
    private const ROLE_PRIORITY = [
        'guest'       => 0,
        'user'        => 1,
        'moderator'   => 2,
        'admin'       => 3,
        'superadmin'  => 4,
    ];

    public function getRoleByTelegramId(int $telegramId): string
    {
        $user = $this->telegramUserRepository->getBoundUserByTelegramId($telegramId);
        if (!$user) {
            // Bootstrap env-суперадмина: заявленный в BOT_SUPERADMIN_TELEGRAM_ID аккаунт
            // получает права сразу, даже если он ещё не /start'ил бота и не в БД.
            $superId = (int) config('services.bot.superadmin_telegram_id', 0);
            if ($superId > 0 && $telegramId === $superId) {
                $this->superAdminProvisioner->ensure($telegramId);
                return 'superadmin';
            }

            return 'guest';
        }

        // Роли из Spatie (guard web по умолчанию)
        $roleNames = $user->getRoleNames(); // Collection<string>

        if ($roleNames->isEmpty()) {
            // учётка связана, но без ролей — трактуем как базового пользователя
            return 'user';
        }

        $normalized = $roleNames->map(fn ($n) => strtolower((string) $n));
        $best = 'guest';
        $bestScore = self::ROLE_PRIORITY[$best];

        foreach ($normalized as $name) {
            if (isset(self::ROLE_PRIORITY[$name]) && self::ROLE_PRIORITY[$name] > $bestScore) {
                $best = $name;
                $bestScore = self::ROLE_PRIORITY[$name];
            }
        }

        return $best;
    }
}
