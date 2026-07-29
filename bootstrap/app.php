<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'bot.auth' => \App\Http\Middleware\BotAuthMiddleware::class,

            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Если нужно повесить bot.auth на весь API-групп — раскомментируй:
        // $middleware->appendToGroup('api', \App\Http\Middleware\BotAuthMiddleware::class);

        /*
         * Неавторизованный запрос к закрытому эндпоинту должен получать 401, а не 500.
         * По умолчанию Laravel уводит гостя на именованный маршрут `login`; в этом
         * приложении такого маршрута нет (вход — POST api/admin/auth/login), поэтому
         * получался RouteNotFoundException → 500 с HTML-страницей ошибки, а при
         * включённой отладке — со стеком вызовов. Проверено: до правки
         * GET /api/admin/communities без токена отвечал 500.
         *
         * Это только API: возвращаем null, то есть перенаправлять некуда, и Laravel
         * отдаёт честный AuthenticationException → 401 JSON.
         */
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Это API: ошибки отдаём JSON'ом всегда, а не только когда клиент прислал
         * Accept: application/json. Иначе неавторизованный запрос без этого заголовка
         * уходил в HTML-ветку обработчика, там срабатывал redirect на именованный
         * маршрут `login` (которого в этом приложении нет — вход это POST
         * api/admin/auth/login), и вместо 401 получался RouteNotFoundException → 500
         * с HTML-страницей ошибки, а при включённой отладке — со стеком вызовов.
         *
         * Замер до правки: GET /api/admin/communities без токена — 500 и HTML.
         * После: 401 и {"message":"Unauthenticated."} с заголовком и без него.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
