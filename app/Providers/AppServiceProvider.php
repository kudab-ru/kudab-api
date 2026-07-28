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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
}
