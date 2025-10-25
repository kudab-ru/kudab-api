<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BotAuthMiddleware
{
    /**
     * Простой сервисный доступ по Bearer-токену.
     * Значение берётся из config('services.bot.shared_token').
     */
    public function handle(Request $request, Closure $next): Response
    {
        $providedToken = $request->bearerToken();
        $expectedToken = (string) config('services.bot.shared_token');

        if (!$providedToken || !$expectedToken || !hash_equals($expectedToken, $providedToken)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
