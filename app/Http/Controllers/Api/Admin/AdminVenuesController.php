<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Text\TextLock;
use App\Support\FuzzySearch;
use App\Support\VenueDuplicateFinder;
use App\Support\VenueKindLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
            // Поиск нестрогий — тот же рецепт, что у поиска событий на сайте:
            // подстрока плюс триграммы. Строгий ILIKE не прощал ни опечатки, ни
            // другого падежа: «дивнагорье» и «никитинског» не находили ничего.
            ->when($q !== '', function ($query) use ($q) {
                $like = FuzzySearch::like($q);
                $token = FuzzySearch::token($q);
                $fuzzy = FuzzySearch::applicable($token);

                $query->where(function ($w) use ($like, $token, $fuzzy) {
                    $w->whereRaw('public.ru_normalize(v.name) LIKE ?', [$like])
                        ->orWhereRaw('public.ru_normalize(coalesce(v.address, \'\')) LIKE ?', [$like]);

                    if ($fuzzy) {
                        $thr = FuzzySearch::threshold($token);
                        $w->orWhereRaw('word_similarity(?, public.ru_normalize(v.name)) >= ?', [$token, $thr]);
                    }
                });

                // Точные совпадения выше похожих, иначе опечатка-сосед лезет
                // вперёд буквального попадания.
                if ($fuzzy) {
                    $query->orderByRaw(
                        'CASE WHEN public.ru_normalize(v.name) LIKE ? THEN 0 ELSE 1 END',
                        [$like]
                    )->orderByRaw('word_similarity(?, public.ru_normalize(v.name)) DESC', [$token]);
                }
            })
            ->orderBy('v.name')
            ->limit(200)
            ->get([
                'v.id', 'v.name', 'v.slug', 'v.kind', 'v.address', 'v.latitude', 'v.longitude',
                'v.house_fias_id', 'v.source_meta', 'v.created_at', 'c.name as city_name', 'v.city_id',
                'v.parent_id', 'v.description', 'v.avatar_url',
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

        // Имя родителя и число вложенных: оба берутся по ВСЕМУ каталогу, а не по
        // текущей странице — иначе при поиске родитель «пропадал» бы из карточки
        // только потому, что сам не совпал с запросом.
        $parentNames = DB::table('venues')->whereNull('deleted_at')
            ->pluck('name', 'id')->all();
        $childCounts = DB::table('venues')->whereNull('deleted_at')->whereNotNull('parent_id')
            ->groupBy('parent_id')->selectRaw('parent_id, count(*) c')
            ->pluck('c', 'parent_id')->all();

        return response()->json(['data' => $venues->map(function ($v) use ($eventCounts, $communityCounts, $parentNames, $childCounts) {
            $meta = is_string($v->source_meta) ? json_decode($v->source_meta, true) : (array) $v->source_meta;

            return [
                'id' => (int) $v->id,
                'name' => $v->name,
                'slug' => $v->slug,
                // Тип каталога: список его не отдавал, поэтому увидеть «у скольких
                // площадок он пуст» из админки было нельзя — а пуст он у 70 из 125.
                'kind' => $v->kind,
                'kind_manual' => (bool) ($meta['manual_kind'] ?? false),
                // Родитель — физическое вложение (сцена в парке). События не
                // поднимаются, это только факт «внутри».
                'parent_id' => $v->parent_id !== null ? (int) $v->parent_id : null,
                'parent_name' => $v->parent_id !== null ? ($parentNames[(int) $v->parent_id] ?? null) : null,
                'children_count' => (int) ($childCounts[$v->id] ?? 0),
                'has_description' => $v->description !== null && trim((string) $v->description) !== '',
                'avatar_url' => $v->avatar_url,
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
        })->values(), 'meta' => [
            // Белый список видов едет вместе со списком — чтобы админка не держала
            // свою копию и не разъезжалась с сервером при добавлении вида.
            'kinds' => VenueKindLabel::CANONICAL,
            // Кандидаты в родители: ВЕСЬ каталог, а не текущая страница. Список
            // режется поиском, и без этого при активном поиске выбрать родителя
            // было бы не из чего. 125 строк по три поля — дешевле второго запроса.
            // parent_id тут нужен клиенту, чтобы вычеркнуть потомков из выбора:
            // считать их по видимому списку нельзя — он режется поиском, и при
            // активном поиске потомок не нашёлся бы, а кольцо предложилось бы.
            'parent_options' => DB::table('venues')->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name', 'city_id', 'parent_id'])
                ->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'name' => $p->name,
                    'city_id' => (int) $p->city_id,
                    'parent_id' => $p->parent_id !== null ? (int) $p->parent_id : null,
                ]),
        ]]);
    }

    /**
     * Кандидаты на слияние. Правила — VenueDuplicateFinder, то же, что показывает
     * `parser:venues:duplicates`; здесь они нужны экраном, чтобы не искать пары
     * глазами по всему каталогу (на проде 20.09.2026 это 125 карточек).
     */
    /**
     * «Это разные площадки» — снять пару из кандидатов навсегда.
     *
     * Пишем в source_meta ОБЕИМ сторонам: находилка не знает, с какой стороны
     * её позовут, а пара симметрична. Колонки под это не завожу — признак
     * редкий, живёт в jsonb рядом с остальными пометками и снимается так же.
     */
    public function notDuplicate(Request $request, int $id): JsonResponse
    {
        $otherId = (int) $request->validate([
            'other_id' => ['required', 'integer'],
        ])['other_id'];
        abort_if($otherId === $id, 422, 'Нужны две разные площадки');

        $pair = DB::table('venues')->whereIn('id', [$id, $otherId])->whereNull('deleted_at')
            ->get(['id', 'name', 'source_meta'])->keyBy('id');
        abort_if($pair->count() !== 2, 404, 'Одна из площадок не найдена');

        foreach ([[$id, $otherId], [$otherId, $id]] as [$self, $other]) {
            $meta = json_decode((string) ($pair[$self]->source_meta ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $list = array_values(array_unique(array_map('intval', $meta['not_duplicate_of'] ?? [])));
            if (! in_array($other, $list, true)) {
                $list[] = $other;
            }
            $meta['not_duplicate_of'] = $list;
            DB::table('venues')->where('id', $self)
                ->update(['source_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }

        Log::info('admin:venues:not-duplicate', [
            'actor_id' => $request->user()?->id,
            'venue_id' => $id,
            'other_id' => $otherId,
        ]);

        return response()->json(['data' => [
            'a' => $pair[$id]->name,
            'b' => $pair[$otherId]->name,
        ]]);
    }

    public function duplicates(): JsonResponse
    {
        $venues = DB::table('venues as v')
            ->whereNull('v.deleted_at')
            ->leftJoin(DB::raw('(select venue_id, count(*) c from events where deleted_at is null group by venue_id) ec'),
                'ec.venue_id', '=', 'v.id')
            ->get([
                'v.id', 'v.city_id', 'v.name', 'v.kind', 'v.address', 'v.latitude', 'v.longitude',
                'v.house_fias_id', 'v.source_meta', 'v.parent_id', DB::raw('coalesce(ec.c, 0) as events_count'),
            ]);

        $pairs = VenueDuplicateFinder::pairs($venues);

        return response()->json(['data' => $pairs, 'meta' => [
            'venues_total' => $venues->count(),
            'pairs_total' => count($pairs),
        ]]);
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
            // Категория каталога. Поля тут не было вовсе, поэтому исправить тип
            // площадки было нельзя ничем: инференс по имени стоит вне расписания
            // и без --overwrite трогает только пустые, а форма его не отдавала.
            // Замер прода 20.09.2026: у 70 площадок из 125 тип пуст, а у всех трёх
            // кинотеатров неверен — «Синема Парк Галерея Чижова» числился музеем
            // (правило «галере» стоит раньше), «Спартак» и «Юность» сценами
            // (правило «театр» ловит подстроку в слове «кинотеатр»).
            'kind' => ['sometimes', 'nullable', Rule::in(VenueKindLabel::CANONICAL)],
            // Физическое вложение: сцена в парке, зал во дворце. Проверки ниже —
            // сам себе не родитель, тот же город, без циклов.
            'parent_id' => ['sometimes', 'nullable', 'integer'],
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

        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parentId = (int) $data['parent_id'];
            abort_if($parentId === $id, 422, 'Площадка не может быть внутри самой себя');

            $parent = DB::table('venues')->where('id', $parentId)->whereNull('deleted_at')
                ->first(['id', 'city_id', 'parent_id', 'name']);
            abort_if($parent === null, 422, 'Родительская площадка не найдена');
            abort_if((int) $parent->city_id !== (int) $venue->city_id, 422,
                'Родитель должен быть в том же городе');

            // Цикл: поднимаемся по цепочке от предполагаемого родителя вверх.
            // Глубина каталога мала, но без гарда пара «A внутри B, B внутри A»
            // подвесила бы любой обход дерева.
            $cursor = $parent;
            $guard = 0;
            while ($cursor?->parent_id !== null && $guard++ < 32) {
                abort_if((int) $cursor->parent_id === $id, 422,
                    'Так получится кольцо: «'.$parent->name.'» уже находится внутри этой площадки');
                $cursor = DB::table('venues')->where('id', $cursor->parent_id)->first(['id', 'parent_id', 'name']);
            }

            $data['parent_id'] = $parentId;
        }

        $lat = isset($data['lat']) ? (float) $data['lat'] : null;
        $lon = isset($data['lon']) ? (float) $data['lon'] : null;
        unset($data['lat'], $data['lon']);

        $update = $data + ['updated_at' => now()];

        // Метка «тип поставлен руками» — по образцу manual_point выше. Сегодня
        // venues:infer-kind без --overwrite трогает только пустые и ручной тип не
        // затрёт, но команда переживёт эту правку: пусть признак лежит заранее.
        if (array_key_exists('kind', $data)) {
            $update['source_meta'] = DB::raw(sprintf(
                "COALESCE(source_meta, '{}'::jsonb) || '%s'::jsonb",
                json_encode([
                    'manual_kind' => true,
                    'manual_kind_at' => now()->toIso8601String(),
                    'manual_kind_by' => $request->user()?->id,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));
        }

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
            ->first(['id', 'name', 'kind', 'address', 'description', 'tg_portrait', 'latitude', 'longitude'])]);
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
