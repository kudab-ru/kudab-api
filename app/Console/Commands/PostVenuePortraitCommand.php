<?php

namespace App\Console\Commands;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Services\Telegram\TelegramVenuePortraitService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Ручная постановка портрета площадки в очередь канала (средство управления).
 *
 * Уважает защиту «одно в полёте» (без --force) — если что-то уже в очереди,
 * второй пост не ставим. Доставка — тот же bot-cron. Под ревью-гейтом уходит
 * в личку owner, иначе — сразу в канал.
 */
class PostVenuePortraitCommand extends Command
{
    protected $signature = 'broadcast:venue-portrait:post
        {--venue= : id площадки (обязательно)}
        {--chat= : telegram_chat_id канала (по умолчанию — единственный enabled)}
        {--force : Игнорировать защиту «одно в полёте»}';

    protected $description = 'Вручную поставить портрет площадки в очередь канала (с защитой от двойного поста)';

    public function handle(TelegramVenuePortraitService $service): int
    {
        $venueId = (int) $this->option('venue');
        if ($venueId <= 0) {
            $this->error('Укажи --venue=<id>.');

            return self::INVALID;
        }

        $broadcast = $this->resolveBroadcast();
        if (! $broadcast) {
            return self::INVALID;
        }

        $chat = $broadcast->chat;
        $reviewGate = (bool) config('services.bot.broadcast_review_gate');
        $reviewer = $chat?->owner?->telegram_id;

        try {
            $item = $service->enqueueVenueManually(
                (int) $broadcast->id,
                $venueId,
                Carbon::now(),
                (bool) $this->option('force'),
                $reviewGate,
                $reviewer ? (int) $reviewer : null,
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Портрет площадки #%d поставлен в очередь: item #%d, статус %s.%s',
            $venueId,
            $item->id,
            $item->status,
            $reviewGate
                ? ' Ушло на ревью в личку owner — одобри в боте.'
                : ' Поллер отправит в канал в течение минуты.',
        ));

        return self::SUCCESS;
    }

    private function resolveBroadcast(): ?TelegramChatBroadcast
    {
        $chatOpt = $this->option('chat');
        if ($chatOpt !== null && $chatOpt !== '') {
            $chat = TelegramChat::query()->where('telegram_chat_id', (int) $chatOpt)->first();
            if (! $chat) {
                $this->error("Канал с telegram_chat_id={$chatOpt} не найден.");

                return null;
            }
            $broadcast = TelegramChatBroadcast::query()->where('chat_id', $chat->id)->first();
            if (! $broadcast) {
                $this->error('У канала нет настроек рассылки (chat_broadcasts).');

                return null;
            }

            return $broadcast->load('chat.owner');
        }

        $enabled = TelegramChatBroadcast::query()->where('enabled', true)->with('chat.owner')->get();
        if ($enabled->count() === 1) {
            return $enabled->first();
        }
        if ($enabled->isEmpty()) {
            $this->error('Нет включённых каналов. Задай --chat.');
        } else {
            $this->error('Включённых каналов несколько — укажи --chat=<telegram_chat_id>.');
        }

        return null;
    }
}
