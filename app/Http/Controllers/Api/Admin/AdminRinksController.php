<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Web\RinksController;
use App\Http\Controllers\Controller;
use App\Models\Rink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Страница «Катки» в админке: открыть сезон, поменять часы, цены и ссылки без выката. */
final class AdminRinksController extends Controller
{
    public function index(): JsonResponse
    {
        $rinks = Rink::query()->with(['city:id,slug', 'venue:id,name'])->orderBy('city_id')->orderBy('sort')->orderBy('id')->get();

        return response()->json(['data' => $rinks->map(fn (Rink $r) => $r->toArray() + [
            'venue_name' => $r->venue?->name,
            'checked_days' => $r->checked_at !== null ? (int) $r->checked_at->diffInDays(now()) : null,
        ])->values()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rink = Rink::query()->with('city:id,slug')->findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'name_in' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string|max:255',
            'area' => 'sometimes|nullable|string|max:80',
            'indoor' => 'sometimes|boolean',
            'ice' => 'sometimes|nullable|in:artificial,natural',
            'entry' => 'sometimes|in:online,cash,free,phone',
            'status' => 'sometimes|in:open,soon,call,closed',
            'status_note' => 'sometimes|nullable|string|max:255',
            'status_short' => 'sometimes|nullable|string|max:40',
            'status_source' => 'sometimes|nullable|string|max:255',
            'opens_on' => 'sometimes|nullable|date',
            'schedule' => 'sometimes|nullable|array',
            'schedule.*.days' => 'required_with:schedule|array',
            'schedule.*.days.*' => 'integer|between:1,7',
            'schedule.*.label' => 'required_with:schedule|string|max:40',
            'schedule.*.times' => 'required_with:schedule|array',
            'schedule.*.times.*' => ['string', 'regex:/^\d{1,2}:\d{2}–\d{1,2}:\d{2}$/u'],
            'sessions' => 'sometimes|nullable|array',
            'sessions.*.date' => 'required_with:sessions|date_format:Y-m-d',
            'sessions.*.times' => 'required_with:sessions|array',
            'sessions.*.times.*' => ['string', 'regex:/^\d{1,2}:\d{2}–\d{1,2}:\d{2}$/u'],
            'closures' => 'sometimes|nullable|array',
            'closures.*.date' => 'required_with:closures|date_format:Y-m-d',
            'closures.*.note' => 'required_with:closures|string|max:120',
            'prices' => 'sometimes|nullable|array',
            'features' => 'sometimes|nullable|array',
            'features.*' => 'string|max:60',
            'kids' => 'sometimes|boolean',
            'links' => 'sometimes|nullable|array',
            'links.*' => 'nullable|string|max:500',
            'week_note' => 'sometimes|nullable|string|max:255',
            'hidden' => 'sometimes|boolean',
            'sort' => 'sometimes|integer|between:0,999',
        ]);

        // правка руками и есть проверка: дата «проверено» на сайте сдвигается вместе с ней
        $rink->fill($data + ['checked_at' => now()])->save();
        Cache::forget(RinksController::cacheKey((string) $rink->city?->slug));

        return response()->json(['data' => $rink->fresh()]);
    }

    /** Ничего не поменялось, но каток проверен: на сайте «проверено» встаёт на сегодня. */
    public function checked(int $id): JsonResponse
    {
        $rink = Rink::query()->with('city:id,slug')->findOrFail($id);
        $rink->forceFill(['checked_at' => now()])->save();
        Cache::forget(RinksController::cacheKey((string) $rink->city?->slug));

        return response()->json(['data' => $rink->fresh()]);
    }
}
