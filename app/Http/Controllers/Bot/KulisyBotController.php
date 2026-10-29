<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bot;

use App\Http\Controllers\Api\Kulisy\KulisyAuthController;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Kulisy\TelegramLoginCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Ответ человека в боте на «Это вы?». Регистрация заводит организатора без почты и пароля
 * и привязывает к нему этот Telegram; вход находит уже привязанного.
 */
class KulisyBotController extends Controller
{
    public function __construct(private readonly TelegramLoginCodes $codes) {}

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'answer' => ['required', 'in:yes,no'],
            'telegram_id' => ['required', 'integer'],
            'username' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        $row = $this->codes->get($data['code']);
        if ($row === null || $row['status'] !== 'pending') {
            return response()->json(['data' => ['status' => 'expired']]);
        }
        if ($data['answer'] === 'no') {
            $this->codes->put($data['code'], ['status' => 'declined'] + $row);

            return response()->json(['data' => ['status' => 'declined', 'intent' => $row['intent']]]);
        }

        $tg = TelegramUser::updateOrCreate(['telegram_id' => $data['telegram_id']], [
            'telegram_username' => $data['username'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'last_active' => now(),
        ]);
        $user = $tg->user_id ? User::find($tg->user_id) : null;
        $organizer = $user?->hasAnyRole(KulisyAuthController::ROLES) ?? false;

        if (! $organizer && $row['intent'] === 'register') {
            $user ??= User::create(['name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: ($data['username'] ?? 'организатор')]);
            $user->assignRole('organizer');
            $tg->forceFill(['user_id' => $user->id])->save();
            $organizer = true;
        }

        $status = $organizer ? 'confirmed' : 'no_account';
        $this->codes->put($data['code'], ['status' => $status, 'user_id' => $organizer ? $user->id : null] + $row);

        return response()->json(['data' => ['status' => $status, 'intent' => $row['intent']]]);
    }
}
