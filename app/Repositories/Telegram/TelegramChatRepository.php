<?php

namespace App\Repositories\Telegram;

use App\Contracts\Telegram\TelegramChatRepositoryInterface;
use App\Models\TelegramChat;
use Illuminate\Support\Collection;

class TelegramChatRepository implements TelegramChatRepositoryInterface
{
    public function findByTelegramChatId(int $telegramChatId): ?TelegramChat
    {
        return TelegramChat::query()
            ->where('telegram_chat_id', $telegramChatId)
            ->first();
    }

    public function getByTelegramUserId(int $telegramUserId, bool $onlyActive = true): Collection
    {
        $query = TelegramChat::query()
            ->where('telegram_user_id', $telegramUserId);

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return $query
            ->orderByDesc('linked_at')
            ->orderByDesc('id')
            ->get();
    }

    public function linkChat(
        int $telegramUserId,
        int $telegramChatId,
        string $chatType,
        ?string $title = null,
        ?string $username = null,
    ): TelegramChat {
        $now = now();

        $chat = TelegramChat::query()
            ->where('telegram_chat_id', $telegramChatId)
            ->first();

        if (!$chat) {
            $chat = new TelegramChat();
            $chat->telegram_chat_id = $telegramChatId;
        }

        $chat->telegram_user_id = $telegramUserId;
        $chat->chat_type        = $chatType;
        $chat->title            = $title;
        $chat->username         = $username;
        $chat->is_active        = true;
        $chat->linked_at        = $now;
        $chat->unlinked_at      = null;

        $chat->save();

        return $chat->refresh();
    }

    public function getByTelegramChatId(int $telegramChatId, bool $onlyActive = true): Collection
    {
        $query = TelegramChat::query()
            ->where('telegram_chat_id', $telegramChatId);

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return $query
            ->orderByDesc('linked_at')
            ->orderByDesc('id')
            ->get();
    }


    public function unlinkChat(TelegramChat $chat): TelegramChat
    {
        if (!$chat->is_active && $chat->unlinked_at) {
            return $chat;
        }

        $chat->is_active   = false;
        $chat->unlinked_at = now();
        $chat->save();

        return $chat->refresh();
    }
}
