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

        // последние 5 ранов на профиль одним запросом (профилей мало)
        $slugs = $profiles->pluck('slug')->all();
        $runs = DB::table('source_runs')
            ->whereIn('source_slug', $slugs)
            ->orderByDesc('id')
            ->limit(count($slugs) * 5 + 50)
            ->get()
            ->groupBy('source_slug');

        $data = $profiles->map(function ($p) use ($runs, $eventCounts) {
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
                'community_id' => isset($eventCounts[$p->slug]) ? (int) $eventCounts[$p->slug]->community_id : null,
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
        abort_if(($profile->parse_mode ?? 'jsonld') !== 'jsonld', 422,
            'Re-probe только для jsonld-профилей: шаблон llm_text выбирает человек');

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

    /** green — последний завершённый ран принёс посты; red — 3 нулевых подряд; yellow — иное. */
    private function health($finishedRuns): string
    {
        if ($finishedRuns->isEmpty()) {
            return 'yellow';
        }
        if ((int) $finishedRuns->first()->posts_ok > 0) {
            return 'green';
        }
        $lastThree = $finishedRuns->take(3);

        return count($lastThree) >= 3 && $lastThree->every(fn ($r) => (int) $r->posts_ok === 0)
            ? 'red'
            : 'yellow';
    }
}
