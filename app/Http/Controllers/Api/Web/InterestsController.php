<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Resources\WebInterestResource;
use App\Models\Event;
use App\Models\Interest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public каталог интересов (Этап 2).
 *
 *   GET /api/web/interests              — все интересы плоским массивом
 *   GET /api/web/interests?city=slug    — events_count считается в скоупе города
 *   GET /api/web/interests?parent_slug= — отфильтровать только children
 *   GET /api/web/interests?q=           — ILIKE по name
 *
 * Кэш Cache::remember 10 мин по (city, parent_slug, q). Инвалидация при
 * interests:sync (см. SyncInterestsFromCsv).
 *
 * events_count = сколько ПРЕДСТОЯЩИХ событий темы человек увидит, кликнув по ней.
 * Считается тем же скоупом видимости, что и лента (Event::visibleWeb), — раньше
 * тут был свой подзапрос, синхронизированный с лентой только по временно́му
 * окну, и числа расходились втрое. Замер по «Кино» до правки: чип обещал 20, лента
 * отдавала 12 карточек, из них не прошедших — 8. Разрыв давали три фильтра ленты,
 * которых у подзапроса не было: status='active' (−2 события needs_geo), чёрный список
 * источников и таксономия общегородской ленты (−6 детских и семейных показов).
 */
class InterestsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cityId     = $this->resolveCityId($request);
        $parentSlug = trim((string) $request->input('parent_slug', '')) ?: null;
        $q          = trim((string) $request->input('q', '')) ?: null;

        // v2 в ключе: правило подсчёта events_count изменилось (предстоящие + скоуп
        // видимости ленты). Без смены ключа старые числа жили бы ещё 10 минут после
        // выкатки, и «починили, а всё по-старому» выглядело бы как невыкаченная правка.
        $cacheKey = sprintf(
            'interests:catalog:v2:%s:%s:%s',
            $cityId ?? 'all',
            $parentSlug ?? '-',
            $q !== null ? md5($q) : '-'
        );

        $items = Cache::remember($cacheKey, 600, function () use ($cityId, $parentSlug, $q) {
            return $this->fetch($cityId, $parentSlug, $q);
        });

        return response()->json([
            'data' => WebInterestResource::collection($items)->toArray(request()),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetch(?int $cityId, ?string $parentSlug, ?string $q)
    {
        // Коррелированный подзапрос по каждой теме. Условия видимости НЕ пишем руками:
        // берём Event::visibleWeb() — тот же скоуп, что у ленты (не удалено + город
        // active + не в чёрном списке источников + таксономия общегородской ленты).
        // Ровно из-за ручного дублирования условий счётчик и разъехался с лентой.
        //
        // status='active' добавляем отдельно: visibleWeb его НЕ содержит, хотя докблок
        // скоупа обещает «тот же статус-скоуп, что паблик-выдача». Это расхождение самого
        // скоупа — из-за него события needs_geo попадают и в счётчики площадок
        // (VenuesController тоже зовёт visibleWeb без статуса). Чинить надо в скоупе,
        // но это заденет выдачу площадок — вынесено отдельной задачей.
        //
        // Окно — ПРЕДСТОЯЩИЕ, а не lookback ленты: у числа возле темы роль обещания
        // («столько можно посетить»), а не описи. Лента при этом честно показывает
        // и прошедшие за последние 7 дней, но внизу и приглушёнными.
        $countSub = Event::query()
            ->selectRaw('COUNT(*)')
            ->join('event_interest as ei', 'ei.event_id', '=', 'events.id')
            ->whereColumn('ei.interest_id', 'interests.id')
            ->visibleWeb()
            ->where('events.status', 'active')
            ->where(function ($w) {
                $w->whereRaw('events.start_time >= NOW()')
                    ->orWhere(function ($x) {
                        $x->whereNull('events.start_time')
                            ->whereNotNull('events.start_date')
                            ->whereRaw("events.start_date >= (NOW() AT TIME ZONE 'Europe/Moscow')::date");
                    });
            })
            ->when($cityId !== null, fn ($qq) => $qq->where('events.city_id', $cityId));

        return Interest::query()
            ->leftJoin('interests as p', 'p.id', '=', 'interests.parent_id')
            ->when($parentSlug !== null, fn ($qq) => $qq->where('p.slug', $parentSlug))
            ->when($q !== null, fn ($qq) => $qq->where('interests.name', 'ILIKE', '%' . $q . '%'))
            ->select([
                'interests.*',
                DB::raw('p.slug as parent_slug'),
            ])
            ->selectSub($countSub, 'events_count')
            ->orderByRaw('interests.parent_id ASC NULLS FIRST')
            ->orderBy('interests.name')
            ->get();
    }

    private function resolveCityId(Request $request): ?int
    {
        $slug = trim((string) $request->input('city', ''));
        if ($slug === '') {
            return null;
        }
        $id = DB::table('cities')->where('slug', $slug)->where('status', 'active')->value('id');
        return $id ? (int) $id : null;
    }
}
