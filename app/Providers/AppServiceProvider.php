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
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
