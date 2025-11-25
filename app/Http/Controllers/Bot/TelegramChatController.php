<?php

namespace App\Http\Controllers\Bot;

use App\Http\Controllers\Controller;
use App\Models\TelegramChat;
use App\Services\Telegram\TelegramChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TelegramChatController extends Controller
{
    public function __construct(
        private readonly TelegramChatService $service,
    ) {}

    /**
     * GET /api/bot/telegram-chats/by-telegram/{telegram_id}
     * Ответ: { telegram_id, chats: [...] }
     */
    public function listByTelegram(int $telegramId): JsonResponse
    {
        $chats = $this->service->listChatsByTelegramId($telegramId);

        return response()->json([
            'telegram_id' => $telegramId,
            'chats' => $chats->map(fn (TelegramChat $chat) => [
                'id'               => $chat->id,
                'telegram_chat_id' => $chat->telegram_chat_id,
                'chat_type'        => $chat->chat_type,
                'title'            => $chat->title,
                'username'         => $chat->username,
                'city_id'          => $chat->city_id,
                'city_name'        => optional($chat->city)->name,
                'city_country'     => optional($chat->city)->country_code,
            ]),
        ]);
    }

    /**
     * POST /api/bot/telegram-chats/link
     *
     * Вход:
     * {
     *   "telegram_id": 123,
     *   "chat_id": -100500,
     *   "chat_type": "channel",
     *   "title": "Название",
     *   "username": "my_channel"
     * }
     */
    public function link(Request $request): JsonResponse
    {
        $v = validator($request->all(), [
            'telegram_id' => ['required','integer'],
            'chat_id'     => ['required','integer'],
            'chat_type'   => ['required','string','max:32'],
            'title'       => ['sometimes','nullable','string','max:255'],
            'username'    => ['sometimes','nullable','string','max:255'],
        ])->validate();

        try {
            $chat = $this->service->linkChat(
                (int)$v['telegram_id'],
                (int)$v['chat_id'],
                (string)$v['chat_type'],
                $v['title'] ?? null,
                $v['username'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'forbidden',
                'message' => $e->getMessage(),
            ], 403);
        }

        return response()->json([
            'ok'   => true,
            'chat' => $this->serializeChat($chat),
        ]);
    }

    /**
     * POST /api/bot/telegram-chats/unlink
     *
     * Вход:
     * {
     *   "chat_id": -100500
     * }
     *
     * Ответ:
     * {
     *   "ok": true,
     *   "users": [
     *     { "telegram_id": 123 },
     *     ...
     *   ],
     *   "chats": [ ... ] // сериализованные TelegramChat, можно использовать для логов/отладки
     * }
     */
    public function unlink(Request $request): JsonResponse
{
    $v = validator($request->all(), [
        'chat_id' => ['required','integer'],
    ])->validate();

    $chatId = (int)$v['chat_id'];

    $chats = $this->service->forceUnlinkByChatId($chatId);

    // На всякий случай, но после фикса сервиса это уже Eloquent\Collection
    if (method_exists($chats, 'load')) {
        $chats->load('owner');
    }

    return response()->json([
        'ok'    => true,
        'users' => $chats
            ->map(function (TelegramChat $chat) {
                $telegramUser = $chat->owner;
                if (!$telegramUser) {
                    return null;
                }

                return [
                    'telegram_id' => $telegramUser->telegram_id,
                ];
            })
            ->filter()
            ->values(),
        'chats' => $chats
            ->map(fn (TelegramChat $chat) => $this->serializeChat($chat))
            ->values(),
    ]);
}


    private function serializeChat(TelegramChat $chat): array
    {
        return [
            'id'               => $chat->id,
            'telegram_user_id' => $chat->telegram_user_id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'chat_type'        => $chat->chat_type,
            'title'            => $chat->title,
            'username'         => $chat->username,
            'is_active'        => (bool)$chat->is_active,
            'linked_at'        => $chat->linked_at?->toIso8601String(),
            'unlinked_at'      => $chat->unlinked_at?->toIso8601String(),
        ];
    }
}
