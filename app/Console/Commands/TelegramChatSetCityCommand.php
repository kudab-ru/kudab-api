<?php

namespace App\Console\Commands;

use App\Models\TelegramChat;
use App\Services\Telegram\TelegramChatService;
use Illuminate\Console\Command;

/**
 * Задать город каналу автопостинга (по telegram_chat_id) — оператором, без проверки прав.
 * Без города `broadcast:enqueue-due` пропускает канал (skipped_no_city) → ничего не постит.
 *
 *   php artisan telegram:chat:set-city -1004383033164 voronezh
 */
class TelegramChatSetCityCommand extends Command
{
    protected $signature = 'telegram:chat:set-city {chat_id : telegram_chat_id канала} {city : slug или id города}';

    protected $description = 'Задать город каналу автопостинга по telegram_chat_id';

    public function handle(TelegramChatService $service): int
    {
        $chatId = (int) $this->argument('chat_id');
        $cityRef = (string) $this->argument('city');

        $chat = TelegramChat::query()->where('telegram_chat_id', $chatId)->first();
        if (!$chat) {
            $this->error("Канал не найден: telegram_chat_id={$chatId}");
            return self::FAILURE;
        }

        try {
            $chat = $service->forceSetChatCity($chat, $cityRef);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Канал {$chatId} → город #{$chat->city_id} (" . (optional($chat->city)->name ?? '?') . ')');

        return self::SUCCESS;
    }
}
