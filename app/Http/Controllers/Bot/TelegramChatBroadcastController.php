<?php

namespace App\Http\Controllers\Bot;

use App\Http\Controllers\Controller;
use App\Models\TelegramMessageTemplate;
use App\Services\Telegram\TelegramChatBroadcastService;
use App\Services\Telegram\TelegramMessageTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class TelegramChatBroadcastController extends Controller
{
    public function __construct(
        private readonly TelegramChatBroadcastService $broadcastService,
        private readonly TelegramMessageTemplateService $templateService,
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

    /**
     * Список доступных шаблонов для рассылки 1 события.
     *
     * GET /api/bot/broadcast/templates?locale=ru
     *
     * Ответ:
     * {
     *   "ok": true,
     *   "locale": "ru",
     *   "type": "single",
     *   "templates": [
     *     {
     *       "code": "single_basic",
     *       "name": "Полная карточка",
     *       "description": "Заголовок, дата, город, описание, ссылка.",
     *       "show_images": true,
     *       "max_images": 3
     *     },
     *     ...
     *   ]
     * }
     */
    public function listTemplates(Request $request): JsonResponse
    {
        $locale = (string) $request->input('locale', 'ru');

        $templates = $this->templateService
            ->listSingleTemplates($locale)
            ->map(function (TelegramMessageTemplate $tpl) {
                return [
                    'code'        => $tpl->code,
                    'name'        => $tpl->name,
                    'description' => $tpl->description,
                    'locale'      => $tpl->locale,
                    'show_images' => (bool) $tpl->show_images,
                    'max_images'  => (int) $tpl->max_images,
                    'body'        => $tpl->body,
                ];
            })
            ->values();

        return response()->json([
            'ok'        => true,
            'locale'    => $locale,
            'templates' => $templates,
        ]);
    }


    /**
     * Подбор одного события для предпросмотра / ручной отправки.
     *
     * POST /api/bot/broadcast/pick-single
     *
     * Body:
     * {
     *   "telegram_id": 123456789,
     *   "telegram_chat_id": -1001234567890,
     *   "mode": "preview" | "publish"
     * }
     *
     * Ответ:
     * { "ok": true, "event_id": "12345" }
     * или
     * { "ok": false, "error": "..." }
     */
    public function pickSingle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_id'      => ['required', 'integer'],
            'telegram_chat_id' => ['required', 'integer'],
            'mode'             => ['nullable', 'string', 'in:preview,publish'],
        ]);

        $telegramId     = (int) $validated['telegram_id'];
        $telegramChatId = (int) $validated['telegram_chat_id'];
        $mode           = (string) ($validated['mode'] ?? 'preview');

        try {
            // 👉 вся бизнес-логика — в сервисе
            $eventId = $this->broadcastService->pickSingleEventId(
                telegramId: $telegramId,
                telegramChatId: $telegramChatId,
                mode: $mode,
            );

            if (!$eventId) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'Подходящих событий пока нет.',
                ]);
            }

            return response()->json([
                'ok'       => true,
                'event_id' => (string) $eventId,
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
                'error' => 'Не удалось подобрать событие для рассылки.',
            ]);
        }
    }

    /**
     * Отметить одно событие как опубликованное в канале.
     *
     * POST /api/bot/broadcast/mark-single-posted
     *
     * Body:
     * {
     *   "telegram_id": 123456789,
     *   "telegram_chat_id": -1001234567890,
     *   "event_id": 174,
     *   "posted_at": "2025-11-21T16:30:00+03:00" // опционально
     * }
     *
     * Ответ:
     * { "ok": true } или { "ok": false, "error": "..." }
     */
    public function markSinglePosted(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_id'      => ['required', 'integer'],
            'telegram_chat_id' => ['required', 'integer'],
            'event_id'         => ['required', 'integer'],
            'posted_at'        => ['nullable', 'string'], // парсим сами
        ]);

        $telegramId     = (int) $validated['telegram_id'];
        $telegramChatId = (int) $validated['telegram_chat_id'];
        $eventId        = (int) $validated['event_id'];
        $postedAtRaw    = $validated['posted_at'] ?? null;

        $moment = null;
        if ($postedAtRaw) {
            try {
                $moment = new \DateTimeImmutable($postedAtRaw);
            } catch (\Exception $e) {
                // если формат кривой — просто игнорируем и считаем now()
                $moment = null;
            }
        }

        try {
            $this->broadcastService->markSingleEventPostedForChat(
                $telegramId,
                $telegramChatId,
                $eventId,
                $moment,
            );

            return response()->json([
                'ok' => true,
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
                'error' => 'Не удалось отметить событие как опубликованное.',
            ]);
        }
    }
}
