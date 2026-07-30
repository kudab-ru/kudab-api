<?php

namespace App\Providers;

use App\Contracts\Telegram\BotRoleServiceInterface;
use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Contracts\Telegram\TelegramChatRepositoryInterface;
use App\Contracts\Telegram\TelegramMessageTemplateRepositoryInterface;
use App\Contracts\Telegram\TelegramUserRepositoryInterface;
use App\Models\TelegramChatBroadcast;
use App\Repositories\Telegram\TelegramChatBroadcastItemRepository;
use App\Repositories\Telegram\TelegramChatBroadcastRepository;
use App\Repositories\Telegram\TelegramChatRepository;
use App\Repositories\Telegram\TelegramMessageTemplateRepository;
use App\Repositories\Telegram\TelegramUserRepository;
use App\Services\Telegram\BotRoleService;
use App\Support\DatabaseSafety;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BotRoleServiceInterface::class, BotRoleService::class);
        $this->app->bind(TelegramUserRepositoryInterface::class, TelegramUserRepository::class);
        $this->app->bind(TelegramChatRepositoryInterface::class, TelegramChatRepository::class);
        $this->app->bind(TelegramChatBroadcastRepositoryInterface::class, TelegramChatBroadcastRepository::class);
        $this->app->bind(TelegramMessageTemplateRepositoryInterface::class, TelegramMessageTemplateRepository::class);
        $this->app->bind(TelegramChatBroadcastItemRepositoryInterface::class, TelegramChatBroadcastItemRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardDestructiveDatabaseCommands();

        RateLimiter::for('web', function (Request $request) {
            // Вне прода лимита нет вовсе — он только мешает разработке.
            if (! $this->app->isProduction()) {
                return Limit::none();
            }

            // SSR Nuxt ходит в API из внутренней сети докера: у всех посетителей
            // это один адрес, общий счётчик выбирался бы мгновенно. Подставить
            // такой адрес снаружи нельзя — наружу открыт только nginx, а до PHP
            // запрос доходит по fastcgi с настоящим REMOTE_ADDR.
            if (IpUtils::isPrivateIp((string) $request->ip())) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->ip());
        });
    }

    private function guardDestructiveDatabaseCommands(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (DatabaseSafety::looksLikeTestDatabase($database) || DatabaseSafety::wipeAllowedByOperator()) {
            return;
        }

        FreshCommand::prohibit();
        RefreshCommand::prohibit();
        ResetCommand::prohibit();
        WipeCommand::prohibit();

        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($database): void {
            $blocked = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'];

            if (! in_array($event->command, $blocked, true)) {
                return;
            }

            throw new RuntimeException(self::destructiveCommandRefusal((string) $event->command, $database));
        });
    }

    private static function destructiveCommandRefusal(string $command, string $database): string
    {
        return implode(PHP_EOL, array_merge(
            [
                '',
                "Команда {$command} отменена: база «{$database}» не тестовая.",
                'Она снесла бы все таблицы, а вместе с ними данные разработки.',
                '',
            ],
            DatabaseSafety::HINT_LINES,
            [
                '',
                'Если базу разработки правда надо собрать заново, поставьте перед командой KUDAB_ALLOW_DB_WIPE=1.',
                '',
            ]
        ));
    }
}
