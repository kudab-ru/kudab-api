<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Authentication для админ-панели (kudab-admin). Bearer-token подход:
 * фронт логинится POST /api/admin/auth/login → получает токен → шлёт
 * `Authorization: Bearer <token>` в дальнейших запросах.
 *
 * Cookie SPA-режим Sanctum не используем намеренно — у админки отдельный
 * subdomain (admin.kudab.ru) и Bearer проще, чем CSRF + stateful домен.
 */
class AdminAuthController extends Controller
{
    private const ALLOWED_ROLES = ['admin', 'superadmin'];

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Защита от brute-force: 5 попыток на email+ip за минуту
        $key = $this->throttleKey($data['email'], $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Слишком много попыток. Повтори через {$seconds} сек.",
            ], 429);
        }

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            return response()->json(['message' => 'Неверный email или пароль'], 401);
        }

        if (!$user->hasAnyRole(self::ALLOWED_ROLES)) {
            return response()->json(['message' => 'Нет доступа в админку'], 403);
        }

        RateLimiter::clear($key);

        // Имя токена включает client info — пригодится для отзыва конкретной сессии
        $tokenName = sprintf('admin:%s:%s', $request->userAgent() ?? 'unknown', $request->ip() ?? 'unknown');
        $token = $user->createToken($tokenName, ['admin'])->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => $this->serializeUser($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        return response()->json(['data' => $this->serializeUser($user)]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id'    => (int) $user->id,
            'email' => (string) $user->email,
            'name'  => (string) ($user->name ?? ''),
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }

    private function throttleKey(string $email, ?string $ip): string
    {
        return 'admin-login:' . strtolower($email) . '|' . ($ip ?? 'unknown');
    }
}
