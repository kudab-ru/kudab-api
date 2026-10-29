<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Web\RinksController;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Rink;
use App\Services\Rinks\RinkDays;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Страница «Катки» в админке: завести каток, открыть сезон, поменять часы, цены и ссылки без выката. */
final class AdminRinksController extends Controller
{
    private const STALE_DAYS = 14;

    public function index(): JsonResponse
    {
        $rinks = Rink::query()->with(['city:id,slug', 'venue:id,name'])->orderBy('city_id')->orderBy('sort')->orderBy('id')->get();
        $today = CarbonImmutable::now('Europe/Moscow')->startOfDay();

        return response()->json(['data' => $rinks->map(fn (Rink $r) => $r->toArray() + [
            'venue_name' => $r->venue?->name,
            'checked_days' => $r->checked_at !== null ? (int) $r->checked_at->diffInDays(now()) : null,
            'todo' => $this->todo($r, $today),
        ])->values()]);
    }

    /**
     * Что проверить у катка: список над карточками в админке. Праздники смотрим на две недели вперёд:
     * без своих часов на сайте стоят воскресные с пометкой «часы могут отличаться», см. RinkDays::day.
     *
     * @return list<string>
     */
    private function todo(Rink $r, CarbonImmutable $today): array
    {
        if ($r->hidden) {
            return [];
        }
        $out = [];
        if ($r->status === 'soon' && $r->opens_on !== null && $r->opens_on->toDateString() <= $today->toDateString()) {
            $out[] = 'обещал открыться '.$r->opens_on->locale('ru')->translatedFormat('j F').', проверьте, открылся ли';
        }
        if ($r->checked_at === null || $r->checked_at->diffInDays(now()) >= self::STALE_DAYS) {
            $out[] = $r->checked_at === null ? 'ни разу не проверяли' : 'не проверяли с '.$r->checked_at->locale('ru')->translatedFormat('j F');
        }
        if ($r->status === 'open') {
            $guess = [];
            foreach (RinkDays::HOLIDAYS as $iso) {
                $d = CarbonImmutable::parse($iso, 'Europe/Moscow');
                if ($d->betweenIncluded($today, $today->addDays(14)) && RinkDays::day($r, $d, null)['holiday'] !== null) {
                    $guess[] = $d->locale('ru')->translatedFormat('j F');
                }
            }
            if ($guess !== []) {
                $out[] = 'праздники без своих часов: '.implode(', ', $guess);
            }
        }

        return $out;
    }

    /** Новый каток заводится скрытым: на сайт он выходит, когда в него внесут часы и цены и снимут «скрыть». */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => 'sometimes|string|exists:cities,slug',
            'name' => 'required|string|max:255',
            'indoor' => 'required|boolean',
            'address' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:80',
            'lat' => 'nullable|numeric|between:-90,90',
            'lon' => 'nullable|numeric|between:-180,180',
        ]);
        $city = City::query()->where('slug', $data['city'] ?? 'voronezh')->firstOrFail();

        $base = Str::slug($data['name']) ?: 'katok';
        $slug = $base;
        for ($n = 2; Rink::query()->where('city_id', $city->id)->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }
        $rink = Rink::query()->create(array_diff_key($data, ['city' => true]) + [
            'city_id' => $city->id,
            'slug' => $slug,
            'status' => 'soon',
            'hidden' => true,
            'sort' => (int) Rink::query()->where('city_id', $city->id)->max('sort') + 10,
            'checked_at' => now(),
        ]);

        return response()->json(['data' => $rink->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rink = Rink::query()->with('city:id,slug')->findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'name_in' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string|max:255',
            'area' => 'sometimes|nullable|string|max:80',
            'lat' => 'sometimes|nullable|numeric|between:-90,90',
            'lon' => 'sometimes|nullable|numeric|between:-180,180',
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
            'about' => 'sometimes|nullable|string|max:255',
            'session_note' => 'sometimes|nullable|string|max:255',
            'way' => 'sometimes|nullable|string|max:255',
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
