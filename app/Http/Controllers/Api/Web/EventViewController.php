<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\EventViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/web/events/{id}/view — просмотр карточки для своего счётчика, см. `EventViews`.
 */
final class EventViewController extends Controller
{
    public function __invoke(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'v' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
        ]);

        if (! Event::query()->whereKey($id)->exists()) {
            return response()->json(['ok' => false], 404);
        }

        return response()->json(['ok' => true, 'counted' => EventViews::record($request, $id, $data['v'])]);
    }
}
