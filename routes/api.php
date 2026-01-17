<?php

use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\Web\CitiesController;
use App\Http\Controllers\Bot\RoleController;
use App\Http\Controllers\Bot\TelegramChatBroadcastController;
use App\Http\Controllers\Bot\TelegramChatController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Web\EventsController as WebEventsController;

Route::get('/ping', function () {
    return response()->json([
        'ok' => true,
        'result' => 'pong',
    ]);
});

Route::middleware(['throttle:web'])->group(function () {
    Route::get('events', [EventController::class, 'index']);
    Route::get('events/{id}', [EventController::class, 'show']);
});

Route::prefix('web')->middleware(['throttle:web'])->group(function () {
    Route::get('ping', fn () => ['ok' => true, 'result' => 'pong']);
    Route::get('events', [WebEventsController::class, 'index']);
    Route::get('events/{id}', [WebEventsController::class, 'show'])->whereNumber('id');

    Route::get('cities', [CitiesController::class, 'index']);
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
