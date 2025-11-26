<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\TelegramUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Команда для связи Telegram-аккаунта с супер-админом.
 *
 * Допущения (подправь под свой проект):
 * - Модель TelegramUser находится в App\Models\TelegramUser.
 * - В таблице telegram_users есть поля telegram_id и telegram_username.
 * - Есть связь $telegramUser->user() -> belongsTo(User::class).
 * - Роли либо через Spatie (assignRole / getRoleNames),
 *   либо через boolean-колонку users.is_superadmin,
 *   либо через строковую колонку users.role.
 */
class BotSuperAdmin extends Command
{
    /**
     * Пример использования:
     *  php artisan bot:superadmin 8307201745
     *  php artisan bot:superadmin @nickname --mode=find
     *  php artisan bot:superadmin 8307201745 --email=dev@example.test --name="Dev Admin"
     */
    protected $signature = 'bot:superadmin
        {telegram : Telegram ID или @username}
        {--email= : Email web-пользователя (для создания)}
        {--name= : Имя web-пользователя (для создания)}
        {--mode=ensure : Режим: ensure (по умолчанию) или find}';

    protected $description = 'Найти/создать супер-админа по Telegram ID/username и связать с TelegramUser';

    public function handle(): int
    {
        $rawTelegram = trim((string) $this->argument('telegram'));
        $mode = strtolower((string) ($this->option('mode') ?? 'ensure'));

        if (! in_array($mode, ['ensure', 'find'], true)) {
            $this->error("Неизвестный режим: {$mode}. Используй ensure или find.");

            return self::FAILURE;
        }

        [$searchBy, $tgId, $tgUsername] = $this->normalizeTelegram($rawTelegram);

        if ($mode === 'find') {
            return $this->handleFind($searchBy, $tgId, $tgUsername);
        }

        return $this->handleEnsure($searchBy, $tgId, $tgUsername);
    }

    /**
     * Разобрать аргумент: число → telegram_id, иначе → username.
     *
     * @return array{0:string,1:int|null,2:string|null}
     */
    protected function normalizeTelegram(string $raw): array
    {
        if (preg_match('/^\d+$/', $raw)) {
            return ['id', (int) $raw, null];
        }

        $username = ltrim($raw, '@');

        return ['username', null, $username];
    }

    protected function handleFind(string $searchBy, ?int $tgId, ?string $tgUsername): int
    {
        $telegramUser = $this->findTelegramUser($searchBy, $tgId, $tgUsername);

        if (! $telegramUser) {
            $this->warn('🔍 TelegramUser не найден в базе.');
            $this->line('Искали по ' . ($searchBy === 'id'
                    ? "telegram_id = {$tgId}"
                    : "telegram_username = @{$tgUsername}"
                ));

            return self::SUCCESS;
        }

        $this->info('✅ Найден TelegramUser:');
        $foundTgId = data_get($telegramUser, 'telegram_id');
        $foundUsername = data_get($telegramUser, 'telegram_username');

        $this->line('  Telegram ID: ' . ($foundTgId ?? '-'));
        $this->line('  Username: ' . ($foundUsername ? '@' . $foundUsername : '-'));
        $this->line('  ID записи: ' . $telegramUser->getKey());
        $this->line('  Создан: ' . ($telegramUser->created_at ?: '-'));
        $this->line('  Обновлён: ' . ($telegramUser->updated_at ?: '-'));

        $user = $telegramUser->user;

        if (! $user) {
            $this->warn('Связанный web-пользователь (User) не найден (user_id пустой).');

            return self::SUCCESS;
        }

        $this->info('👤 Связанный User:');
        $this->line('  ID: ' . $user->getKey());
        $this->line('  Email: ' . ($user->email ?? '-'));
        $this->line('  Имя: ' . ($user->name ?? '-'));

        if (method_exists($user, 'getRoleNames')) {
            $roles = $user->getRoleNames()->implode(', ');
            $this->line('  Роли: ' . ($roles ?: '-'));
        } elseif ($this->userHasColumn($user, 'is_superadmin')) {
            $this->line('  is_superadmin: ' . ($user->is_superadmin ? '1' : '0'));
        } elseif ($this->userHasColumn($user, 'role')) {
            $this->line('  role: ' . ($user->role ?? '-'));
        }

        return self::SUCCESS;
    }

    protected function handleEnsure(string $searchBy, ?int $tgId, ?string $tgUsername): int
    {
        $emailOption = $this->option('email');
        $nameOption = $this->option('name');

        $this->info('🚀 Режим ensure: гарантируем супер-админа для Telegram-аккаунта.');
        $this->line('  Вход: ' . ($searchBy === 'id'
                ? "telegram_id = {$tgId}"
                : "telegram_username = @{$tgUsername}"
            ));

        $createdUser = false;
        $createdTelegramUser = false;

        try {
            DB::beginTransaction();

            $telegramUser = $this->findTelegramUser($searchBy, $tgId, $tgUsername);

            if ($telegramUser) {
                $this->line('➕ Нашли существующий TelegramUser (ID записи: ' . $telegramUser->getKey() . ').');

                if ($telegramUser->user) {
                    $user = $telegramUser->user;
                    $this->line('➕ TelegramUser уже связан с User ID ' . $user->getKey() . '.');
                } else {
                    $this->line('ℹ️ TelegramUser ещё не связан с User. Создаём/ищем web-пользователя.');

                    [$user, $createdUser] = $this->ensureUserForTelegram($telegramUser, $emailOption, $nameOption);

                    $telegramUser->user()->associate($user);
                    $telegramUser->save();
                    $this->line('✅ Привязали TelegramUser к User ID ' . $user->getKey() . '.');
                }
            } else {
                $this->line('ℹ️ TelegramUser не найден. Создаём web-пользователя и (при наличии ID) TelegramUser.');

                [$user, $createdUser] = $this->createUserIfNeeded(
                    $emailOption,
                    $nameOption,
                    $tgId,
                    $tgUsername
                );

                if ($tgId !== null) {
                    $telegramUser = $this->createTelegramUser($user, $tgId, $tgUsername);
                    $createdTelegramUser = true;

                    $this->line('✅ Создан TelegramUser ID записи ' . $telegramUser->getKey() . ' и привязан к User.');
                } else {
                    $telegramUser = null;
                    $this->warn('⚠️ Telegram ID не передан, создали только User без TelegramUser.');
                }
            }

            $this->ensureSuperadminRole($user);

            DB::commit();

            $this->info('🎉 Готово. Итоговая сводка:');
            $this->line('  User ID: ' . $user->getKey());
            $this->line('  Email: ' . $user->email);
            $this->line('  Имя: ' . $user->name);
            if (method_exists($user, 'getRoleNames')) {
                $roles = $user->getRoleNames()->implode(', ');
                $this->line('  Роли: ' . ($roles ?: '-'));
            }

            $this->line('  Создан новый User: ' . ($createdUser ? 'да' : 'нет'));
            $this->line('  Создан новый TelegramUser: ' . ($createdTelegramUser ? 'да' : 'нет'));

            if ($telegramUser) {
                $this->line('  Telegram ID: ' . (data_get($telegramUser, 'telegram_id') ?? '-'));
                $this->line('  Telegram username: ' . (data_get($telegramUser, 'telegram_username') ? '@'.data_get($telegramUser, 'telegram_username') : '-'));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('💥 Ошибка при выполнении: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    protected function findTelegramUser(string $searchBy, ?int $tgId, ?string $tgUsername): ?TelegramUser
    {
        $query = TelegramUser::query();

        if ($searchBy === 'id' && $tgId !== null) {
            $query->where('telegram_id', $tgId);
        } elseif ($tgUsername !== null) {
            $query->where('telegram_username', $tgUsername);
        } else {
            return null;
        }

        return $query->first();
    }

    /**
     * Гарантировать, что у TelegramUser есть связанный User.
     *
     * @return array{0:User,1:bool} [$user, $created]
     */
    protected function ensureUserForTelegram(TelegramUser $telegramUser, ?string $emailOption, ?string $nameOption): array
    {
        if ($telegramUser->user) {
            return [$telegramUser->user, false];
        }

        $tgId = data_get($telegramUser, 'telegram_id');
        $tgUsername = data_get($telegramUser, 'telegram_username');

        return $this->createUserIfNeeded($emailOption, $nameOption, $tgId, $tgUsername);
    }

    /**
     * Найти/создать User (по email) и вернуть его.
     *
     * @return array{0:User,1:bool} [$user, $created]
     */
    protected function createUserIfNeeded(
        ?string $emailOption,
        ?string $nameOption,
        ?int $tgId,
        ?string $tgUsername
    ): array {
        $email = $this->buildEmail($emailOption, $tgId, $tgUsername);

        // Если такой email уже есть — переиспользуем пользователя.
        $user = User::where('email', $email)->first();
        if ($user) {
            return [$user, false];
        }

        $name = $nameOption ?: $this->buildName($tgId, $tgUsername);
        $password = Str::random(32);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        return [$user, true];
    }

    protected function buildEmail(?string $emailOption, ?int $tgId, ?string $tgUsername): string
    {
        if ($emailOption) {
            return $emailOption;
        }

        $base = $tgUsername
            ? 'tg-' . strtolower($tgUsername)
            : 'tg-' . (string) ($tgId ?? 'unknown');

        $domain = 'example.test';
        $email = $base . '@' . $domain;

        $i = 1;
        while (User::where('email', $email)->exists()) {
            $email = $base . '+' . $i . '@' . $domain;
            $i++;
        }

        return $email;
    }

    protected function buildName(?int $tgId, ?string $tgUsername): string
    {
        if ($tgUsername) {
            return 'TG @' . $tgUsername;
        }

        if ($tgId !== null) {
            return 'TG #' . $tgId;
        }

        return 'TG Superadmin';
    }

    protected function createTelegramUser(User $user, ?int $tgId, ?string $tgUsername): TelegramUser
    {
        $telegramUser = new TelegramUser();

        if ($tgId !== null) {
            $telegramUser->telegram_id = $tgId;
        }

        if ($tgUsername !== null) {
            $telegramUser->telegram_username = $tgUsername;
        }

        $telegramUser->user()->associate($user);
        $telegramUser->save();

        return $telegramUser;
    }

    /**
     * Назначить пользователю роль superadmin с учётом разных подходов к ролям.
     */
    protected function ensureSuperadminRole(User $user): void
    {
        // Вариант 1: Spatie permissions
        if (method_exists($user, 'assignRole')) {
            if (method_exists($user, 'hasRole') && $user->hasRole('superadmin')) {
                $this->line('🔐 Роль superadmin уже назначена (Spatie).');

                return;
            }

            $user->assignRole('superadmin');
            $this->line('🔐 Назначили роль superadmin через Spatie.');

            return;
        }

        $table = $user->getTable();

        // Вариант 2: Boolean-колонка is_superadmin
        if (Schema::hasColumn($table, 'is_superadmin')) {
            if (! $user->is_superadmin) {
                $user->is_superadmin = true;
                $user->save();
                $this->line('🔐 Установили users.is_superadmin = 1.');
            } else {
                $this->line('🔐 users.is_superadmin уже = 1.');
            }

            return;
        }

        // Вариант 3: Строковая колонка role
        if (Schema::hasColumn($table, 'role')) {
            if ($user->role !== 'superadmin') {
                $user->role = 'superadmin';
                $user->save();
                $this->line('🔐 Установили users.role = superadmin.');
            } else {
                $this->line('🔐 users.role уже = superadmin.');
            }

            return;
        }

        $this->warn('⚠️ Не удалось автоматически назначить роль superadmin. Подправь ensureSuperadminRole() под свою систему ролей.');
    }

    protected function userHasColumn(User $user, string $column): bool
    {
        return Schema::hasColumn($user->getTable(), $column);
    }
}
