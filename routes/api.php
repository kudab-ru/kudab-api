<?php

use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Bot\RoleController;
use App\Http\Controllers\Bot\TelegramChatBroadcastController;
use App\Http\Controllers\Bot\TelegramChatController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'ok' => true,
        'result' => 'pong',
    ]);
});

Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{id}', [EventController::class, 'show']);

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

    Route::post('/broadcast/pick-single', [TelegramChatBroadcastController::class, 'pickSingle']);
    Route::post('/broadcast/mark-single-posted', [TelegramChatBroadcastController::class, 'markSinglePosted']);
    Route::post('/broadcast/log-manual-send', [TelegramChatBroadcastController::class, 'logManualSend']);

    Route::get('/broadcast/templates', [TelegramChatBroadcastController::class, 'listTemplates']);
});
