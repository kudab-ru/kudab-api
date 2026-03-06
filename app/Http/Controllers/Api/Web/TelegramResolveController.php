<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramCityChannelService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramResolveController extends Controller
{
    public function __construct(
        private readonly TelegramCityChannelService $service,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $data = $this->service->resolve($validated['city'] ?? null);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Telegram channel is not configured.',
            ], 404);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
}
