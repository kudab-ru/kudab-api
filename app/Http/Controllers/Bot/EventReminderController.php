<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bot;

use App\Models\TelegramUser;
use App\Services\Telegram\EventReminders;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Напоминания «Пойду»: бот заводит и снимает их по просьбе человека и отправляет созревшие. */
class EventReminderController extends Controller
{
    public function __construct(private readonly EventReminders $reminders) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telegram_id' => ['required', 'integer'],
            'chat_id' => ['required', 'integer'],
            'event_id' => ['required', 'integer'],
            'username' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);
        TelegramUser::updateOrCreate(['telegram_id' => $data['telegram_id']], [
            'telegram_username' => $data['username'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'chat_id' => $data['chat_id'],
            'last_active' => now(),
        ]);

        return response()->json(['data' => $this->reminders->schedule(
            (int) $data['telegram_id'], (int) $data['chat_id'], (int) $data['event_id'], Carbon::now(),
        )]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telegram_id' => ['required', 'integer'],
            'id' => ['required', 'integer'],
        ]);
        $done = $this->reminders->cancel((int) $data['telegram_id'], (int) $data['id'], Carbon::now());

        return response()->json(['data' => ['status' => $done ? 'cancelled' : 'missing']]);
    }

    public function soon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telegram_id' => ['required', 'integer'],
            'id' => ['required', 'integer'],
        ]);

        return response()->json(['data' => $this->reminders->addSoon((int) $data['telegram_id'], (int) $data['id'], Carbon::now())]);
    }

    public function due(Request $request): JsonResponse
    {
        $limit = max(1, min(50, (int) $request->input('limit', 20)));

        return response()->json(['data' => $this->reminders->due(Carbon::now(), $limit)]);
    }

    public function mark(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'ok' => ['required', 'boolean'],
            'permanent' => ['nullable', 'boolean'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->reminders->mark((int) $data['id'], (bool) $data['ok'], (bool) ($data['permanent'] ?? false), $data['error'] ?? null, Carbon::now());

        return response()->json(['data' => ['status' => 'ok']]);
    }
}
