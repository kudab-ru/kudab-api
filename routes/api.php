<?php

use App\Http\Controllers\Api\Admin\AdminSelectController;
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

use App\Http\Controllers\Api\Admin\AdminCommunitiesController;
use App\Http\Controllers\Api\Admin\AdminEventsController;

Route::prefix('admin')
//    ->middleware(['auth:sanctum', 'role:admin|superadmin'])
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
    });

Route::prefix('web')->middleware(['throttle:web'])->group(function () {
    Route::get('sitemap/events', [WebSitemapController::class, 'events']);

    Route::get('ping', fn () => ['ok' => true, 'result' => 'pong']);
    Route::get('events', [WebEventsController::class, 'index']);
    Route::get('event-groups/{id}', [WebEventGroupsController::class, 'show'])->whereNumber('id');
    Route::get('events/{id}', [WebEventsController::class, 'show'])->whereNumber('id');

    Route::get('cities', [CitiesController::class, 'index']);
    Route::get('telegram/resolve', [TelegramResolveController::class, 'show']);

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
});
