<?php

namespace App\Services\Telegram;

use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Супер-админ из BOT_SUPERADMIN_TELEGRAM_ID, идемпотентно: TelegramUser, web-User и роль.
 * Вызывается из BotRoleService::getRoleByTelegramId, чтобы админ из env работал без CLI и /start.
 * Копия логики bot:superadmin (BotSuperAdmin), правятся вместе.
 */
class SuperAdminProvisioner
{
    public function ensure(int $telegramId, ?string $telegramUsername = null): User
    {
        return DB::transaction(function () use ($telegramId, $telegramUsername) {
            $telegramUser = TelegramUser::query()
                ->where('telegram_id', $telegramId)
                ->first();

            $user = $telegramUser?->user ?: $this->ensureWebUser($telegramId, $telegramUsername);

            if (!$telegramUser) {
                $telegramUser = new TelegramUser();
                $telegramUser->telegram_id = $telegramId;
                if ($telegramUsername) {
                    $telegramUser->telegram_username = $telegramUsername;
                }
                $telegramUser->user()->associate($user);
                $telegramUser->save();
            } elseif (!$telegramUser->user) {
                $telegramUser->user()->associate($user);
                $telegramUser->save();
            }

            // findOrCreate: на незасиженной базе assignRole бросает RoleDoesNotExist
            if (method_exists($user, 'assignRole') && method_exists($user, 'hasRole')) {
                \Spatie\Permission\Models\Role::findOrCreate('superadmin');
                if (!$user->hasRole('superadmin')) {
                    $user->assignRole('superadmin');
                }
            }

            return $user;
        });
    }

    private function ensureWebUser(int $telegramId, ?string $telegramUsername): User
    {
        $base = $telegramUsername
            ? 'tg-' . strtolower($telegramUsername)
            : 'tg-' . $telegramId;
        $email = $base . '@example.test';

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name'     => 'TG Superadmin #' . $telegramId,
                'password' => Hash::make(Str::random(40)),
            ],
        );
    }
}
