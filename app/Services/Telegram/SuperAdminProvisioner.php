<?php

namespace App\Services\Telegram;

use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bootstrap супер-админа из env (BOT_SUPERADMIN_TELEGRAM_ID).
 *
 * Идемпотентно гарантирует, что для telegram_id есть TelegramUser, привязанный к
 * web-User с ролью superadmin. Раньше это делалось только руками через
 * `php artisan bot:superadmin`; теперь self-heal при первой проверке прав
 * (BotRoleService::getRoleByTelegramId) — чтобы заявленный в env админ работал
 * сразу, без CLI и без ручного /start. Та же логика, что у BotSuperAdmin-команды.
 */
class SuperAdminProvisioner
{
    public function ensure(int $telegramId, ?string $telegramUsername = null): User
    {
        return DB::transaction(function () use ($telegramId, $telegramUsername) {
            $telegramUser = TelegramUser::query()
                ->where('telegram_id', $telegramId)
                ->first();

            // 1) web-User: переиспользуем привязанного, иначе детерминированный по tg-id.
            $user = $telegramUser?->user ?: $this->ensureWebUser($telegramId, $telegramUsername);

            // 2) TelegramUser: создаём или до-привязываем к User.
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

            // 3) Роль superadmin (Spatie), идемпотентно. findOrCreate — чтобы не упасть,
            // если роль ещё не засижена (assignRole иначе бросает RoleDoesNotExist).
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
