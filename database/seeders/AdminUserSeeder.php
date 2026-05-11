<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Создаёт первого супер-админа из env (ADMIN_EMAIL / ADMIN_PASSWORD).
 *
 * - Идемпотентен: при повторном запуске только апдейтит пароль/роль, не дублирует.
 * - Если переменные не заданы или дефолтный пароль слабый — пропускает с warning.
 * - Зависит от RolesSeeder (роль superadmin должна существовать).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        $name     = env('ADMIN_NAME', 'Super Admin');

        if (empty($email) || empty($password)) {
            $this->command?->warn('AdminUserSeeder: ADMIN_EMAIL/ADMIN_PASSWORD не заданы, пропускаю.');
            return;
        }

        if (strlen((string) $password) < 8) {
            $this->command?->warn('AdminUserSeeder: ADMIN_PASSWORD короче 8 символов, пропускаю для безопасности.');
            return;
        }

        if (!Role::where(['name' => 'superadmin', 'guard_name' => 'web'])->exists()) {
            $this->command?->warn('AdminUserSeeder: роли superadmin нет (запусти RolesSeeder).');
            return;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'              => $name,
                'password'          => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        // Если пользователь уже существовал — обновляем пароль из env (явное намерение admin'а)
        if (!$user->wasRecentlyCreated && !Hash::check($password, $user->password)) {
            $user->password = Hash::make($password);
            $user->save();
            $this->command?->info("AdminUserSeeder: пароль для {$email} обновлён из env.");
        }

        if (!$user->hasRole('superadmin')) {
            $user->assignRole('superadmin');
        }

        $this->command?->info("AdminUserSeeder: {$email} готов (role=superadmin).");
    }
}
