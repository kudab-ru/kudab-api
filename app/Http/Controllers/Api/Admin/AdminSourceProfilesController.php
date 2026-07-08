<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Админка профильных сайтов-источников (source_profiles, суперадмин).
 *
 * Профиль = «источник × город» само-строящегося парсера (сеть 'site' и
 * builtin qtickets/Я.Афиша живут отдельно). Api пишет, parser читает свежим:
 * тумблер/лимиты действуют со следующего 30-мин цикла, re-probe забирает
 * parser:sources:consume-reprobes (каждые 10 мин).
 */
class AdminSourceProfilesController extends Controller
{
    public function index(): JsonResponse
    {
        $profiles = DB::table('source_profiles')->orderBy('slug')->get();

        // связь профиль → организатор (+его venue) — link network 3, external=slug
        $communityBySlug = DB::table('community_social_links as l')
            ->join('communities as c', 'c.id', '=', 'l.community_id')
            ->leftJoin('venues as v', 'v.id', '=', 'c.venue_id')
            ->where('l.social_network_id', 3)
            ->whereIn('l.external_community_id', $profiles->pluck('slug'))
            ->whereNull('c.deleted_at')
            ->get(['l.external_community_id as slug', 'c.id', 'c.name', 'v.id as venue_id', 'v.name as venue_name'])
            ->keyBy('slug');

        // события профиля за 30 дней: профиль → link (network 3, external=slug) →
        // community → events
        $eventCounts = DB::table('community_social_links as l')
            ->join('events as e', 'e.community_id', '=', 'l.community_id')
            ->where('l.social_network_id', 3)
            ->whereIn('l.external_community_id', $profiles->pluck('slug'))
            ->whereNull('e.deleted_at')
            ->where('e.created_at', '>=', now()->subDays(30))
            ->groupBy('l.external_community_id')
            ->selectRaw('l.external_community_id as slug, count(*) as c, max(l.community_id) as community_id')
            ->get()->keyBy('slug');

        // последние 5 ранов НА КАЖДЫЙ профиль (window, не глобальный лимит —
        // частый профиль иначе вымывает раны редкого и тот выглядит пустым)
        $slugs = $profiles->pluck('slug')->all();
        $runs = collect($slugs === [] ? [] : DB::select(
            'SELECT * FROM (
                SELECT r.*, ROW_NUMBER() OVER (PARTITION BY source_slug ORDER BY id DESC) AS rn
                FROM source_runs r WHERE source_slug = ANY(?)
            ) t WHERE rn <= 5 ORDER BY id DESC',
            ['{'.implode(',', $slugs).'}'],
        ))->groupBy('source_slug');

        $data = $profiles->map(function ($p) use ($runs, $eventCounts, $communityBySlug) {
            $own = ($runs[$p->slug] ?? collect())->take(5)->values();
            $finished = $own->filter(fn ($r) => $r->finished_at !== null);

            return [
                'id' => (int) $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'listing_url' => $p->listing_url,
                'event_url_regex' => $p->event_url_regex,
                'parse_mode' => $p->parse_mode ?? 'jsonld',
                'city_slug' => $p->city_slug,
                'enabled' => (bool) $p->enabled,
                'settings' => is_string($p->settings) ? json_decode($p->settings, true) : $p->settings,
                'probe_meta' => is_string($p->probe_meta) ? json_decode($p->probe_meta, true) : $p->probe_meta,
                'probed_at' => $p->probed_at,
                'reprobe_requested_at' => $p->reprobe_requested_at ?? null,
                'health' => $this->health($finished),
                'events_30d' => (int) ($eventCounts[$p->slug]->c ?? 0),
                'community_id' => isset($communityBySlug[$p->slug]) ? (int) $communityBySlug[$p->slug]->id : null,
                'community_name' => $communityBySlug[$p->slug]->name ?? null,
                'venue_id' => isset($communityBySlug[$p->slug]->venue_id) ? (int) $communityBySlug[$p->slug]->venue_id : null,
                'venue_name' => $communityBySlug[$p->slug]->venue_name ?? null,
                'recent_runs' => $own->map(fn ($r) => [
                    'started_at' => $r->started_at,
                    'finished_at' => $r->finished_at,
                    'status' => $r->status,
                    'urls_total' => (int) $r->urls_total,
                    'posts_ok' => (int) $r->posts_ok,
                    'posts_failed' => (int) $r->posts_failed,
                    'error_text' => $r->error_text,
                ])->all(),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'string', 'max:255'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.listing_limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'settings.delay_ms' => ['sometimes', 'integer', 'min:0', 'max:30000'],
        ]);

        $profile = DB::table('source_profiles')->where('id', $id)->first();
        abort_if($profile === null, 404);

        $update = [];
        foreach (['enabled', 'name'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        if (array_key_exists('settings', $data)) {
            // merge поверх существующих — точечная правка лимитов не съедает
            // остальные ключи (user_agent, таймауты)
            $current = is_string($profile->settings) ? (array) json_decode($profile->settings, true) : [];
            $update['settings'] = json_encode(array_merge($current, $data['settings'] ?? []));
        }
        abort_if($update === [], 422, 'Нечего обновлять');
        $update['updated_at'] = now();

        DB::table('source_profiles')->where('id', $id)->update($update);

        Log::info('admin:source-profiles:update', [
            'actor_id' => $request->user()?->id,
            'profile_id' => $id,
            'fields' => array_keys($update),
        ]);

        return response()->json(['data' => DB::table('source_profiles')->where('id', $id)->first()]);
    }

    public function reprobe(Request $request, int $id): JsonResponse
    {
        $profile = DB::table('source_profiles')->where('id', $id)->first();
        abort_if($profile === null, 404);
        // jsonld: перевыучивает регэксп; llm_text: обновляет разведку (кластеры),
        // выбранный шаблон сохраняется

        DB::table('source_profiles')->where('id', $id)->update([
            'reprobe_requested_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('admin:source-profiles:reprobe-requested', [
            'actor_id' => $request->user()?->id,
            'profile_id' => $id,
            'slug' => $profile->slug,
        ]);

        return response()->json(['data' => ['requested' => true]]);
    }

    /**
     * Привязать/снять ПЛОЩАДКУ (venue) организатора источника. ВАЖНО:
     * community.venue_id — шаг 1 каскада VenueResolver и ПЕРЕКРЫВАЕТ адреса
     * из текстов событий; ставится только для сайтов ОДНОГО места (ДК, клуб),
     * не для музеев с филиалами/фестивалей/агрегаторов.
     */
    public function setVenue(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'venue_id' => ['present', 'nullable', 'integer'],
        ]);

        $profile = DB::table('source_profiles')->where('id', $id)->first();
        abort_if($profile === null, 404);
        $link = DB::table('community_social_links')
            ->where('social_network_id', 3)->where('external_community_id', $profile->slug)->first();
        abort_if($link === null, 422, 'У источника нет линка');

        $venueId = $data['venue_id'] !== null ? (int) $data['venue_id'] : null;
        $venueName = null;
        if ($venueId !== null) {
            $venue = DB::table('venues')->where('id', $venueId)->whereNull('deleted_at')->first(['id', 'name', 'city_id']);
            abort_if($venue === null, 422, 'Площадка не найдена');
            $communityCity = DB::table('communities')->where('id', $link->community_id)->value('city_id');
            abort_if($communityCity !== null && (int) $venue->city_id !== (int) $communityCity,
                422, 'Площадка из другого города');
            $venueName = (string) $venue->name;
        }

        DB::table('communities')->where('id', $link->community_id)
            ->update(['venue_id' => $venueId, 'updated_at' => now()]);

        Log::info('admin:source-profiles:venue-set', [
            'actor_id' => $request->user()?->id,
            'profile_id' => $id,
            'community_id' => (int) $link->community_id,
            'venue_id' => $venueId,
        ]);

        return response()->json(['data' => ['venue_id' => $venueId, 'venue_name' => $venueName]]);
    }

    /** Поиск по каталогу площадок (селектор привязки). */
    public function searchVenues(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $rows = DB::table('venues')
            ->whereNull('deleted_at')
            ->when($q !== '', fn ($query) => $query->where('name', 'ILIKE', '%'.str_replace(['%', '_'], '', $q).'%'))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'address']);

        return response()->json(['data' => $rows]);
    }

    /**
     * Сменить организатора у источника: линк, посты и события ИСТОЧНИКА
     * переезжают транзакцией — ничего не удаляется, старый организатор
     * остаётся (пустой безвреден, чинится обратной перепривязкой).
     * Группы событий перестроятся ночными groups:relink/index/prune.
     */
    public function rebind(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'community_id' => ['required', 'integer'],
        ]);

        $profile = DB::table('source_profiles')->where('id', $id)->first();
        abort_if($profile === null, 404);

        $link = DB::table('community_social_links')
            ->where('social_network_id', 3)
            ->where('external_community_id', $profile->slug)
            ->first();
        abort_if($link === null, 422, 'У источника нет линка (network site)');

        $target = (int) $data['community_id'];
        abort_if((int) $link->community_id === $target, 422, 'Источник уже привязан к этому организатору');
        abort_if(! DB::table('communities')->where('id', $target)->whereNull('deleted_at')->exists(),
            422, 'Организатор не найден');
        abort_if(DB::table('community_social_links')
            ->where('community_id', $target)->where('social_network_id', 3)
            ->where('id', '!=', $link->id)->exists(),
            422, 'У целевого организатора уже есть сайт-источник');

        $moved = ['events' => 0, 'posts' => 0];
        DB::transaction(function () use ($link, $target, &$moved) {
            DB::table('community_social_links')->where('id', $link->id)
                ->update(['community_id' => $target, 'updated_at' => now()]);

            // только контент ЭТОГО источника (по social_link_id постов),
            // чужие события старого организатора не трогаем
            $postIds = DB::table('context_posts')->where('social_link_id', $link->id)->pluck('id');
            $moved['posts'] = DB::table('context_posts')->whereIn('id', $postIds)
                ->update(['community_id' => $target, 'updated_at' => now()]);
            $moved['events'] = DB::table('events')->whereIn('original_post_id', $postIds)
                ->update(['community_id' => $target, 'updated_at' => now()]);
        });

        Log::info('admin:source-profiles:rebound', [
            'actor_id' => $request->user()?->id,
            'profile_id' => $id,
            'from_community' => (int) $link->community_id,
            'to_community' => $target,
            'moved' => $moved,
        ]);

        return response()->json(['data' => [
            'community_id' => $target,
            'community_name' => (string) DB::table('communities')->where('id', $target)->value('name'),
            'moved_events' => $moved['events'],
            'moved_posts' => $moved['posts'],
        ]]);
    }

    /**
     * Здоровье по broken-семантике (как self-heal парсера): broken-ран =
     * упал / листинг не сматчил ни одной ссылки / фетчили и всё провалилось.
     * llm_text с urls>0 и ok=0 (всё уже собрано, новых событий нет) — ЗДОРОВ.
     * idle — ранов ещё не было (профиль новый/выключен).
     */
    private function health($finishedRuns): string
    {
        if ($finishedRuns->isEmpty()) {
            return 'idle';
        }

        $isBroken = fn ($r) => ($r->status ?? null) !== 'ok'
            || (int) $r->urls_total === 0
            || ((int) $r->posts_ok === 0 && (int) $r->posts_failed > 0);

        if (! $isBroken($finishedRuns->first())) {
            return 'green';
        }
        $lastThree = $finishedRuns->take(3);

        return count($lastThree) >= 3 && $lastThree->every($isBroken) ? 'red' : 'yellow';
    }
}
