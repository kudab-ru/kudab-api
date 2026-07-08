<?php

use App\Http\Controllers\Api\Admin\AdminErrorLogsController;
use App\Http\Controllers\Api\Admin\AdminSelectController;
use App\Http\Controllers\Api\Admin\AdminYandexAfishaController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\Web\CitiesController;
use App\Http\Controllers\Api\Web\TelegramResolveController;
use App\Http\Controllers\Api\Web\WebSitemapController;
use App\Http\Controllers\Bot\RoleController;
use App\Http\Controllers\Bot\TelegramChatBroadcastController;
use App\Http\Controllers\Bot\TelegramChatController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Web\EventsController as WebEventsController;
use App\Http\Controllers\Api\Web\EventGroupsController as WebEventGroupsController;

Route::get('/ping', function () {
    return response()->json([
        'ok' => true,
        'result' => 'pong',
    ]);
});

use App\Http\Controllers\Api\Admin\AdminAuthController;
use App\Http\Controllers\Api\Admin\AdminCommunitiesController;
use App\Http\Controllers\Api\Admin\AdminCommunityLinksController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminEventsController;
use App\Http\Controllers\Api\Admin\AdminParsingStatusController;

// Публичные admin endpoint'ы (логин), throttle от brute-force.
// Внутренний RateLimiter в AdminAuthController:: login() — точечно на email+ip.
Route::prefix('admin/auth')
    ->middleware(['throttle:10,1'])
    ->group(function () {
        Route::post('login', [AdminAuthController::class, 'login']);
    });

// Авторизованные admin endpoint'ы для auth-сессии (без role-check —
// логаут и me должны быть доступны любому залогиненному, например
// будущему organizer'у).
Route::prefix('admin/auth')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('me', [AdminAuthController::class, 'me']);
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'role:admin|superadmin'])
    ->group(function () {
        Route::get('select/cities', [AdminSelectController::class, 'cities']);
        Route::get('select/communities', [AdminSelectController::class, 'communities']);
        Route::get('select/interests', [AdminSelectController::class, 'interests']);

        // communities
        Route::get('/communities', [AdminCommunitiesController::class, 'index']);
        Route::get('/communities/{id}', [AdminCommunitiesController::class, 'show']);

        Route::post('/communities/import', [AdminCommunitiesController::class, 'import']);
        Route::post('/communities/{id}/verify', [AdminCommunitiesController::class, 'verify']);

        Route::post('/communities', [AdminCommunitiesController::class, 'store']);
        Route::patch('/communities/{id}', [AdminCommunitiesController::class, 'update']);
        Route::delete('/communities/{id}', [AdminCommunitiesController::class, 'destroy']);
        Route::post('/communities/{id}/restore', [AdminCommunitiesController::class, 'restore']);

        // events
        Route::get('/events', [AdminEventsController::class, 'index']);
        Route::get('/events/{id}', [AdminEventsController::class, 'show']);
        Route::post('/events', [AdminEventsController::class, 'store']);
        Route::patch('/events/{id}', [AdminEventsController::class, 'update']);
        Route::delete('/events/{id}', [AdminEventsController::class, 'destroy']);
        Route::post('/events/{id}/restore', [AdminEventsController::class, 'restore']);

        // community-social-links (статус active|gray|black, аналог make link-ban/unban/gray)
        Route::patch('/community-links/{id}/status', [AdminCommunityLinksController::class, 'updateStatus']);

        // parsing-status (мониторинг frozen-источников + ручная разморозка)
        Route::get('/parsing-status', [AdminParsingStatusController::class, 'index']);
        Route::post('/parsing-status/{linkId}/unfreeze', [AdminParsingStatusController::class, 'unfreeze']);

        // error-logs (просмотрщик ошибок «где и какие» + пометка «решено»)
        Route::get('/error-logs', [AdminErrorLogsController::class, 'index']);
        Route::post('/error-logs/resolve-all', [AdminErrorLogsController::class, 'resolveAll']);
        Route::post('/error-logs/{id}/resolve', [AdminErrorLogsController::class, 'resolve']);

        // dashboard
        Route::get('/dashboard/stats', [AdminDashboardController::class, 'stats']);
    });

// Управление источником Я.Афиша — ТОЛЬКО суперадмин (отдельная группа, НЕ
// role:admin|superadmin выше). Пишет source_configs, parser читает свежим.
// Профильные сайты-источники (source_profiles само-строящегося парсера) —
// тумблер/лимиты действуют со следующего цикла парсера, re-probe через метку.
Route::prefix('admin/sources/profiles')
    ->middleware(['auth:sanctum', 'role:superadmin'])
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\AdminSourceProfilesController::class, 'index']);
        Route::patch('{id}', [\App\Http\Controllers\Api\Admin\AdminSourceProfilesController::class, 'update'])->whereNumber('id');
        Route::post('{id}/reprobe', [\App\Http\Controllers\Api\Admin\AdminSourceProfilesController::class, 'reprobe'])->whereNumber('id');
    });

Route::prefix('admin/sources/yandex-afisha')
    ->middleware(['auth:sanctum', 'role:superadmin'])
    ->group(function () {
        Route::get('config', [AdminYandexAfishaController::class, 'config']);
        Route::put('config', [AdminYandexAfishaController::class, 'updateConfig']);
        Route::get('status', [AdminYandexAfishaController::class, 'status']);
        // Синхронный headless-fetch — троттлим от abuse/anti-bot Я.Афиши.
        Route::post('scan', [AdminYandexAfishaController::class, 'scan'])->middleware('throttle:20,1');
    });

Route::prefix('web')->middleware(['throttle:web'])->group(function () {
    Route::get('sitemap/events', [WebSitemapController::class, 'events']);

    Route::get('ping', fn () => ['ok' => true, 'result' => 'pong']);
    Route::get('events', [WebEventsController::class, 'index']);
    Route::get('events/random', [WebEventsController::class, 'random']);
    Route::get('event-groups/{id}', [WebEventGroupsController::class, 'show'])->whereNumber('id');
    Route::get('events/{id}', [WebEventsController::class, 'show'])->whereNumber('id');
    Route::get('events/{id}/related', [WebEventsController::class, 'related'])->whereNumber('id');

    Route::get('cities', [CitiesController::class, 'index']);
    Route::get('telegram/resolve', [TelegramResolveController::class, 'show']);

    Route::get('interests', [\App\Http\Controllers\Api\Web\InterestsController::class, 'index']);

    // Venues (PR4) — порядок важен: /map ДО /{id} чтобы Laravel routing
    // не съел "map" как numeric id (whereNumber на show всё равно подстрахует).
    Route::get('venues', [\App\Http\Controllers\Api\Web\VenuesController::class, 'index']);
    Route::get('venues/map', [\App\Http\Controllers\Api\Web\VenuesController::class, 'map']);
    Route::get('venues/{id}', [\App\Http\Controllers\Api\Web\VenuesController::class, 'show'])->whereNumber('id');

    Route::post('communities/import', [AdminCommunitiesController::class, 'import']);
    Route::get('communities/{id}', [AdminCommunitiesController::class, 'show'])->whereNumber('id');
    Route::post('communities/{id}/verify', [AdminCommunitiesController::class, 'verify'])->whereNumber('id');
});

Route::prefix('bot')->middleware('bot.auth')->group(function () {
    Route::get('/role/by-telegram/{telegram_id}', [RoleController::class, 'byTelegram']);

    Route::get('/cities', [CityController::class, 'index']); // список/поиск/near
    Route::get('/cities/{city}', [CityController::class, 'show']);

    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{id}', [EventController::class, 'show']);

    Route::get('/telegram-chats/by-telegram/{telegram_id}', [TelegramChatController::class, 'listByTelegram']);
    Route::post('/telegram-chats/link',   [TelegramChatController::class, 'link']);
    Route::post('/telegram-chats/unlink', [TelegramChatController::class, 'unlink']);
    Route::post('/telegram-chats/set-city', [TelegramChatController::class, 'setCity']);

    Route::post('/broadcast/get', [TelegramChatBroadcastController::class, 'getBroadcast']);
    Route::post('/broadcast/update', [TelegramChatBroadcastController::class, 'updateBroadcast']);

    // Подбор одного события
    Route::post('/broadcast/single/pick', [TelegramChatBroadcastController::class, 'pickSingle']);
    // Отметить одно событие как отправленное
    Route::post('/broadcast/single/mark-sent', [TelegramChatBroadcastController::class, 'markSingleSent']);

    Route::post('/broadcast/single/enqueue', [TelegramChatBroadcastController::class, 'enqueueSingle']);
    Route::post('/broadcast/single/queue', [TelegramChatBroadcastController::class, 'listQueue']);
    Route::post('/broadcast/single/queue/skip', [TelegramChatBroadcastController::class, 'skipSingleFromQueue']);
    Route::post(
        '/broadcast/single/run/poll',
        [TelegramChatBroadcastController::class, 'pollSingleRuns'],
    );

    Route::get('/broadcast/templates', [TelegramChatBroadcastController::class, 'listTemplates']);

    // P0.5 approve-in-DM: ревью-гейт автопостинга
    Route::post('/broadcast/review/preview-sent', [TelegramChatBroadcastController::class, 'reviewPreviewSent']);
    Route::post('/broadcast/review/approve', [TelegramChatBroadcastController::class, 'reviewApprove']);
    Route::post('/broadcast/review/reject', [TelegramChatBroadcastController::class, 'reviewReject']);
});
