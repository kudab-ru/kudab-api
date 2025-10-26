<?php

use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Bot\RoleController;
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
});
