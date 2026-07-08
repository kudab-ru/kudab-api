<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Онбординг сайта из админки (PR2): заявка на probe-разведку (выполняет
 * парсер, админка поллит) + создание профиля из результата. Новый профиль
 * ВСЕГДА enabled=false (карантин) — включение отдельным осознанным кликом.
 */
class AdminSourceProbeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listing_url' => ['required', 'url', 'max:500'],
        ]);

        $id = DB::table('source_probe_requests')->insertGetId([
            'listing_url' => rtrim($data['listing_url'], '/'),
            'status' => 'pending',
            'requested_by' => $request->user()?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('admin:source-probe:requested', [
            'actor_id' => $request->user()?->id,
            'request_id' => $id,
            'url' => $data['listing_url'],
        ]);

        return response()->json(['data' => ['id' => $id, 'status' => 'pending']], 201);
    }

    /** Недавние заявки (форма показывает их после перезагрузки страницы). */
    public function index(): JsonResponse
    {
        // sweep: заявка running дольше 10 мин = консьюмер был прерван; честный
        // failed вместо вечного «разведка идёт»
        DB::table('source_probe_requests')
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'failed', 'error' => 'Разведка прервана (консьюмер перезапущен) — запусти заново', 'updated_at' => now()]);

        $rows = DB::table('source_probe_requests')->orderByDesc('id')->limit(5)->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'listing_url' => $r->listing_url,
            'status' => $r->status,
            'error' => $r->error,
            'result' => is_string($r->result) ? json_decode($r->result, true) : $r->result,
        ])->values()]);
    }

    public function show(int $id): JsonResponse
    {
        $req = DB::table('source_probe_requests')->where('id', $id)->first();
        abort_if($req === null, 404);

        return response()->json(['data' => [
            'id' => (int) $req->id,
            'listing_url' => $req->listing_url,
            'status' => $req->status,
            'error' => $req->error,
            'result' => is_string($req->result) ? json_decode($req->result, true) : $req->result,
        ]]);
    }

    /**
     * Создание профиля из результата разведки. jsonld — берём suggested_regex
     * заявки; llm_text — регэксп собирается парсером из шаблона при первом
     * сборе? Нет: собираем здесь той же грамматикой шаблона ({d}/{ds}/{s}).
     */
    public function createProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'probe_request_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'city_slug' => ['required', 'string', 'max:100'],
            'parse_mode' => ['required', 'in:jsonld,llm_text'],
            'template' => ['required_if:parse_mode,llm_text', 'nullable', 'string', 'max:300'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:~^[a-z0-9-]+$~'],
        ]);

        $req = DB::table('source_probe_requests')->where('id', $data['probe_request_id'])->first();
        abort_if($req === null || $req->status !== 'done', 422, 'Заявка не найдена или разведка не завершена');
        $result = is_string($req->result) ? (array) json_decode($req->result, true) : (array) $req->result;

        $cityId = DB::table('cities')->where('slug', $data['city_slug'])->value('id');
        abort_if(! $cityId, 422, "Город '{$data['city_slug']}' не найден");

        $origin = (string) ($result['origin'] ?? '');
        $host = (string) (parse_url($origin)['host'] ?? '');
        $slug = $data['slug'] ?? Str::of($host)->replace('.', '-')->lower()->toString();
        abort_if(DB::table('source_profiles')->where('slug', $slug)->exists(), 422, "Профиль '{$slug}' уже существует");

        if ($data['parse_mode'] === 'jsonld') {
            $regex = (string) ($result['suggested_regex'] ?? '');
            abort_if($regex === '', 422, 'Разведка не нашла Event JSON-LD — jsonld-режим невозможен, выбери llm_text с шаблоном');
        } else {
            $regex = $this->regexFromTemplate($origin, (string) $data['template']);
        }

        DB::transaction(function () use ($data, $slug, $regex, $req, $result, $cityId, $host) {
            DB::table('source_profiles')->insert([
                'slug' => $slug,
                'name' => $data['name'],
                'listing_url' => rtrim((string) $req->listing_url, '/'),
                'event_url_regex' => $regex,
                'city_slug' => $data['city_slug'],
                // КАРАНТИН: включение — отдельный клик после просмотра
                'enabled' => false,
                'parse_mode' => $data['parse_mode'],
                'probe_meta' => json_encode([
                    'clusters' => $result['clusters'] ?? [],
                    'coverage' => $result['coverage'] ?? null,
                    'via' => 'admin_onboarding',
                ], JSON_UNESCAPED_UNICODE),
                'probed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // aggregator-community + link (network 3 'site') — схема сида qtickets:
            // venue_id=NULL, kind=aggregator блокирует HQ-fallback
            $communityId = DB::table('communities')
                ->where('name', $data['name'])->where('city_id', $cityId)->whereNull('deleted_at')->value('id');
            if ($communityId === null) {
                $communityId = DB::table('communities')->insertGetId([
                    'name' => $data['name'],
                    'description' => 'Афиша событий с '.$host,
                    'city_id' => $cityId,
                    'venue_id' => null,
                    'verification_status' => 'approved',
                    'is_verified' => true,
                    'verification_meta' => json_encode([
                        'final' => ['kind' => 'aggregator', 'hq' => ['confidence' => 1.0]],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $networkSiteId = 3;
            // greenfield-safe: сеть 'site' может отсутствовать в свежей БД
            DB::statement(<<<'SQL'
INSERT INTO social_networks (id, name, slug, icon, url_mask, created_at, updated_at)
VALUES (3, 'Сайт', 'site', '🌐', '{slug}', NOW(), NOW())
ON CONFLICT (id) DO NOTHING
SQL);
            $linkExists = DB::table('community_social_links')
                ->where('community_id', $communityId)->where('social_network_id', $networkSiteId)->exists();
            if (! $linkExists) {
                DB::table('community_social_links')->insert([
                    'community_id' => $communityId,
                    'social_network_id' => $networkSiteId,
                    'external_community_id' => $slug,
                    'url' => rtrim((string) $req->listing_url, '/'),
                    'status' => 'active',
                    'last_is_active' => true,
                    'last_has_events' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Log::info('admin:source-probe:profile-created', [
            'actor_id' => $request->user()?->id,
            'slug' => $slug,
            'parse_mode' => $data['parse_mode'],
        ]);

        return response()->json(['data' => ['slug' => $slug, 'enabled' => false]], 201);
    }

    /**
     * Регэксп из шаблона кластера — та же грамматика, что UrlPatternInference
     * парсера ({d}=цифры, {ds}=цифры-слаг, {s}=слаг; literal — как есть).
     * Дублируется осознанно: api не тянет код парсера, грамматика из трёх
     * токенов зафиксирована тестами с обеих сторон.
     */
    private function regexFromTemplate(string $origin, string $template): string
    {
        $parts = array_map(
            fn (string $seg) => match ($seg) {
                '{d}' => '\d+',
                '{ds}' => '\d+-[a-z0-9-]+',
                '{s}' => '[a-z0-9][a-z0-9-]*',
                default => preg_quote($seg, '~'),
            },
            array_values(array_filter(explode('/', trim($template, '/')), fn (string $s) => $s !== '')),
        );

        return '~^'.preg_quote($origin, '~').'/'.implode('/', $parts).'$~i';
    }
}
