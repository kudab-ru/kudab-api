<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Kulisy;

use App\Models\User;
use App\Services\Kulisy\TelegramLoginCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Вход и регистрация через бота: сайт заводит код и спрашивает, что с ним стало,
 * пока человек жмёт «Да» в боте (см. Bot\KulisyBotController).
 */
class KulisyTelegramController extends Controller
{
    public function __construct(private readonly TelegramLoginCodes $codes) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['intent' => ['required', 'in:'.implode(',', TelegramLoginCodes::INTENTS)]]);

        return response()->json(['data' => [
            'code' => $this->codes->create($data['intent']),
            'expires_in' => (int) config('kulisy.telegram_code_ttl'),
        ]]);
    }

    public function status(Request $request, string $code): JsonResponse
    {
        $row = $this->codes->get($code);
        if ($row === null) {
            return response()->json(['data' => ['status' => 'expired']]);
        }
        if ($row['status'] !== 'confirmed') {
            return response()->json(['data' => ['status' => $row['status']]]);
        }

        // Токен выдаётся один раз: второй запрос с тем же кодом его уже не получит.
        $this->codes->forget($code);
        $user = User::findOrFail($row['user_id']);

        return response()->json(['data' => [
            'status' => 'confirmed',
            'token' => KulisyAuthController::issueToken($user, $request),
            'user' => KulisyAuthController::serialize($user),
        ]]);
    }
}
