<?php

namespace App\Http\Controllers\Bot;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramChatBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class TelegramChatBroadcastController extends Controller
{
    public function __construct(
        private readonly TelegramChatBroadcastService $broadcastService,
    ) {}

    /**
     * Получить (или создать) настройки рассылки по telegram_id и telegram_chat_id.
     *
     * POST /api/bot/telegram-chats/broadcast/get
     *
     * Body:
     * {
     *   "telegram_id": 123456789,
     *   "telegram_chat_id": -1001234567890
     * }
     */
    public function getBroadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_id'      => ['required', 'integer'],
            'telegram_chat_id' => ['required', 'integer'],
        ]);

        $telegramId     = (int) $validated['telegram_id'];
        $telegramChatId = (int) $validated['telegram_chat_id'];

        try {
            $broadcast = $this->broadcastService->getSettingsByTelegram(
                $telegramId,
                $telegramChatId,
            );

            return response()->json([
                'ok'   => true,
                'data' => [
                    'enabled'        => (bool) $broadcast->enabled,
                    'period'         => $broadcast->period,         // аксессор
                    'template_code'  => $broadcast->template_code,  // аксессор
                    'last_run_at'    => optional($broadcast->last_run_at)?->toIso8601String(),
                    'last_preview_at'=> optional($broadcast->last_preview_at)?->toIso8601String(),
                ],
            ]);
        } catch (RuntimeException $e) {
            // бизнес-ошибка: права, не тот чат и т.п.
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            // что-то пошло совсем не так — логируем и отдаём общую ошибку
            report($e);

            return response()->json([
                'ok'    => false,
                'error' => 'Не удалось получить настройки рассылки',
            ]);
        }
    }

    /**
     * Обновить настройки рассылки по telegram_id и telegram_chat_id.
     *
     * POST /api/bot/telegram-chats/broadcast/update
     *
     * Body:
     * {
     *   "telegram_id": 123456789,
     *   "telegram_chat_id": -1001234567890,
     *   "enabled": true,
     *   "period": "daily_10",
     *   "template_code": "basic"
     * }
     *
     * period / template_code можно опускать — тогда они не изменятся.
     */
    public function updateBroadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_id'      => ['required', 'integer'],
            'telegram_chat_id' => ['required', 'integer'],
            'enabled'          => ['required', 'boolean'],
            'period'           => ['nullable', 'string', 'max:64'],
            'template_code'    => ['nullable', 'string', 'max:64'],
        ]);

        $telegramId     = (int) $validated['telegram_id'];
        $telegramChatId = (int) $validated['telegram_chat_id'];
        $enabled        = (bool) $validated['enabled'];

        // period / template_code могут не прийти → оставляем null
        $period       = $validated['period'] ?? null;
        $templateCode = $validated['template_code'] ?? null;

        try {
            $broadcast = $this->broadcastService->updateSettingsByTelegram(
                $telegramId,
                $telegramChatId,
                $enabled,
                $period,
                $templateCode,
            );

            return response()->json([
                'ok'   => true,
                'data' => [
                    'enabled'        => (bool) $broadcast->enabled,
                    'period'         => $broadcast->period,
                    'template_code'  => $broadcast->template_code,
                    'last_run_at'    => optional($broadcast->last_run_at)?->toIso8601String(),
                    'last_preview_at'=> optional($broadcast->last_preview_at)?->toIso8601String(),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok'    => false,
                'error' => 'Не удалось сохранить настройки рассылки',
            ]);
        }
    }
}
