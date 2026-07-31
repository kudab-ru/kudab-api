<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Text\TextLock;
use Illuminate\Support\Facades\Log;

/**
 * Админ-каталог площадок (venues, суперадмин). Каталог само-наполняется
 * (cold-resolve: OSM/ЕГРЮЛ/LLM, промоут из событий) — здесь глаз и руки:
 * список со счётчиками, правка имени/адреса, склейка дублей.
 */
class AdminVenuesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $venues = DB::table('venues as v')
            ->leftJoin('cities as c', 'c.id', '=', 'v.city_id')
            ->whereNull('v.deleted_at')
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $term = '%'.str_replace(['%', '_'], '', $q).'%';
                $w->where('v.name', 'ILIKE', $term)->orWhere('v.address', 'ILIKE', $term);
            }))
            ->orderBy('v.name')
            ->limit(200)
            ->get([
                'v.id', 'v.name', 'v.slug', 'v.address', 'v.latitude', 'v.longitude',
                'v.house_fias_id', 'v.source_meta', 'v.created_at', 'c.name as city_name', 'v.city_id',
            ]);

        $ids = $venues->pluck('id');
        $eventCounts = DB::table('events')
            ->whereIn('venue_id', $ids)->whereNull('deleted_at')
            ->groupBy('venue_id')->selectRaw('venue_id, count(*) c,
                count(*) FILTER (WHERE created_at >= NOW() - INTERVAL \'30 days\') c30')
            ->get()->keyBy('venue_id');
        $communityCounts = DB::table('communities')
            ->whereIn('venue_id', $ids)->whereNull('deleted_at')
            ->groupBy('venue_id')->selectRaw('venue_id, count(*) c')
            ->get()->keyBy('venue_id');

        return response()->json(['data' => $venues->map(function ($v) use ($eventCounts, $communityCounts) {
            $meta = is_string($v->source_meta) ? json_decode($v->source_meta, true) : (array) $v->source_meta;

            return [
                'id' => (int) $v->id,
                'name' => $v->name,
                'slug' => $v->slug,
                'address' => $v->address,
                'lat' => $v->latitude !== null ? (float) $v->latitude : null,
                'lon' => $v->longitude !== null ? (float) $v->longitude : null,
                'has_fias' => $v->house_fias_id !== null,
                'city_name' => $v->city_name,
                'city_id' => (int) $v->city_id,
                'via' => $meta['resolved_via'] ?? $meta['origin'] ?? null,
                'events_total' => (int) ($eventCounts[$v->id]->c ?? 0),
                'events_30d' => (int) ($eventCounts[$v->id]->c30 ?? 0),
                'communities' => (int) ($communityCounts[$v->id]->c ?? 0),
                'created_at' => $v->created_at,
            ];
        })->values()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            // правка текста адреса; точку на карте двигают lat/lon ниже
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            // тексты площадки, которые пишет LLM: ручная правка перекрывает генерацию
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tg_portrait' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Точка на карте. Резолверы ошибаются целыми классами — «Парковая, 3»
            // находится в черте города вместо посёлка, OSM отдаёт тёзку, у дома
            // может не быть своих координат. Человеку нужен способ поставить
            // метку рукой, и с этого момента автоматика её не трогает.
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lon' => ['sometimes', 'numeric', 'between:-180,180'],
        ]);
        abort_if($data === [], 422, 'Нечего обновлять');
        // Половина координаты — не координата: required_with тут не помогает,
        // он молчит, когда парного поля вообще нет в запросе.
        abort_if(isset($data['lat']) !== isset($data['lon']), 422, 'Нужны обе координаты: lat и lon');

        $venue = DB::table('venues')->where('id', $id)->whereNull('deleted_at')->first();
        abort_if($venue === null, 404);

        $lat = isset($data['lat']) ? (float) $data['lat'] : null;
        $lon = isset($data['lon']) ? (float) $data['lon'] : null;
        unset($data['lat'], $data['lon']);

        $update = $data + ['updated_at' => now()];

        if ($lat !== null && $lon !== null) {
            $update['location'] = DB::raw(sprintf('ST_SetSRID(ST_Point(%F,%F),4326)', $lon, $lat));
            // Метка «поставлено руками»: по ней парсер пропускает площадку в
            // venues:refine-point, venues:verify-point и перегеокоде. Без неё
            // ночная цепочка вернула бы прежнюю неверную точку.
            $update['source_meta'] = DB::raw(sprintf(
                "COALESCE(source_meta, '{}'::jsonb) || '%s'::jsonb",
                json_encode([
                    'manual_point' => true,
                    'manual_point_at' => now()->toIso8601String(),
                    'manual_point_by' => $request->user()?->id,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));
        }

        DB::table('venues')->where('id', $id)->update($update);

        if ($lat !== null && $lon !== null) {
            $this->moveEventsPinnedToVenue($id, $venue, $lat, $lon);
        }

        TextLock::apply('venues', $id, array_intersect_key($data, array_flip(['description', 'tg_portrait'])));

        Log::info('admin:venues:update', [
            'actor_id' => $request->user()?->id,
            'venue_id' => $id,
            'fields' => array_keys($data + ($lat !== null ? ['lat' => null, 'lon' => null] : [])),
        ]);

        return response()->json(['data' => DB::table('venues')->where('id', $id)
            ->first(['id', 'name', 'address', 'description', 'tg_portrait', 'latitude', 'longitude'])]);
    }

    /**
     * События площадки, стоящие ровно на её прежней точке, взяли координату
     * у неё же — везём вместе, иначе метка переедет, а события останутся в
     * прежнем месте. События с собственной точкой не трогаем.
     */
    private function moveEventsPinnedToVenue(int $venueId, object $venue, float $lat, float $lon): void
    {
        if ($venue->latitude === null || $venue->longitude === null) {
            return;
        }

        DB::table('events')
            ->whereNull('deleted_at')
            ->where('venue_id', $venueId)
            ->whereRaw('abs(latitude - ?) <= 0.000001 AND abs(longitude - ?) <= 0.000001', [$venue->latitude, $venue->longitude])
            ->update([
                'location' => DB::raw(sprintf('ST_SetSRID(ST_Point(%F,%F),4326)', $lon, $lat)),
                'updated_at' => now(),
            ]);
    }

    /**
     * Склейка дублей: события и организаторы дубля переезжают на основную
     * площадку, дубль уходит в архив (soft-delete, обратимо). Обе площадки
     * должны быть из одного города.
     */
    public function merge(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'into_id' => ['required', 'integer', 'different:id'],
        ]);

        $dup = DB::table('venues')->where('id', $id)->whereNull('deleted_at')->first();
        $main = DB::table('venues')->where('id', (int) $data['into_id'])->whereNull('deleted_at')->first();
        abort_if($dup === null || $main === null, 404, 'Площадка не найдена');
        abort_if((int) $dup->id === (int) $main->id, 422, 'Нельзя слить площадку саму в себя');
        abort_if((int) $dup->city_id !== (int) $main->city_id, 422, 'Площадки из разных городов');

        $moved = ['events' => 0, 'communities' => 0];
        DB::transaction(function () use ($dup, $main, &$moved) {
            $moved['events'] = DB::table('events')->where('venue_id', $dup->id)
                ->update(['venue_id' => $main->id, 'updated_at' => now()]);
            $moved['communities'] = DB::table('communities')->where('venue_id', $dup->id)
                ->update(['venue_id' => $main->id, 'updated_at' => now()]);
            DB::table('venues')->where('id', $dup->id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        Log::info('admin:venues:merged', [
            'actor_id' => $request->user()?->id,
            'duplicate_id' => (int) $dup->id,
            'into_id' => (int) $main->id,
            'moved' => $moved,
        ]);

        return response()->json(['data' => [
            'into_id' => (int) $main->id,
            'into_name' => $main->name,
            'moved_events' => $moved['events'],
            'moved_communities' => $moved['communities'],
        ]]);
    }
}
