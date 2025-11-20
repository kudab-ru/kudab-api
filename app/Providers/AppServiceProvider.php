<?php

namespace App\Providers;

use App\Contracts\Telegram\BotRoleServiceInterface;
use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Contracts\Telegram\TelegramChatRepositoryInterface;
use App\Contracts\Telegram\TelegramMessageTemplateRepositoryInterface;
use App\Contracts\Telegram\TelegramUserRepositoryInterface;
use App\Models\TelegramChatBroadcast;
use App\Repositories\Telegram\TelegramChatBroadcastRepository;
use App\Repositories\Telegram\TelegramChatRepository;
use App\Repositories\Telegram\TelegramMessageTemplateRepository;
use App\Repositories\Telegram\TelegramUserRepository;
use App\Services\Telegram\BotRoleService;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
