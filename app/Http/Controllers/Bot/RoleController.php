<?php

namespace App\Http\Controllers\Bot;

use App\Contracts\Telegram\BotRoleServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(
        private readonly BotRoleServiceInterface $botRoleService
    ) {}

    /**
     * GET /api/bot/role/by-telegram/{telegram_id}
     * Ответ: { telegram_id, role }
     */
    public function byTelegram(int $telegramId): JsonResponse
    {
        $role = $this->botRoleService->getRoleByTelegramId($telegramId);

        return response()->json([
            'telegram_id' => $telegramId,
            'role'        => $role,
        ]);
    }
}
