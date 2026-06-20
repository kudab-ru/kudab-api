<?php

namespace Tests\Feature\Telegram;

use App\Contracts\Telegram\BotRoleServiceInterface;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bootstrap env-суперадмина (BOT_SUPERADMIN_TELEGRAM_ID) при проверке прав.
 * Роль superadmin провижнер создаёт сам (findOrCreate) — в setUp не сидируем.
 */
class SuperAdminAutoProvisionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BotRoleServiceInterface
    {
        return app(BotRoleServiceInterface::class);
    }

    public function test_env_superadmin_is_auto_provisioned_on_role_check(): void
    {
        config(['services.bot.superadmin_telegram_id' => 555000111]);

        $role = $this->service()->getRoleByTelegramId(555000111);

        $this->assertSame('superadmin', $role);

        $tu = TelegramUser::query()->where('telegram_id', 555000111)->first();
        $this->assertNotNull($tu, 'TelegramUser создан');
        $this->assertNotNull($tu->user_id, 'привязан к web-User');
        $this->assertTrue($tu->user->hasRole('superadmin'));
    }

    public function test_provision_is_idempotent(): void
    {
        config(['services.bot.superadmin_telegram_id' => 555000111]);

        $this->service()->getRoleByTelegramId(555000111);
        $second = $this->service()->getRoleByTelegramId(555000111);

        $this->assertSame('superadmin', $second);
        $this->assertSame(1, TelegramUser::query()->where('telegram_id', 555000111)->count());
        $this->assertSame(1, User::query()->where('email', 'tg-555000111@example.test')->count());
    }

    public function test_non_superadmin_unbound_stays_guest(): void
    {
        config(['services.bot.superadmin_telegram_id' => 555000111]);

        $role = $this->service()->getRoleByTelegramId(999999);

        $this->assertSame('guest', $role);
        $this->assertSame(0, TelegramUser::query()->where('telegram_id', 999999)->count());
    }

    public function test_zero_config_disables_provision(): void
    {
        config(['services.bot.superadmin_telegram_id' => 0]);

        $role = $this->service()->getRoleByTelegramId(555000111);

        $this->assertSame('guest', $role);
        $this->assertSame(0, TelegramUser::query()->where('telegram_id', 555000111)->count());
    }
}
