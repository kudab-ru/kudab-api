<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\SourceHost;
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

        $url = rtrim($data['listing_url'], '/');
        $host = SourceHost::host($url);

        // дедуп по origin-host (один сайт = один источник): активная заявка на
        // тот же хост — возвращаем её (не плодим headless-работу); свежая done
        // (<1 ч) — переиспользуем результат. Сравнение хоста в PHP: раздел URL
        // не влияет (site.ru/afisha и site.ru/concerts — один сайт), выборка
        // активных/свежих заявок крошечная.
        $candidates = DB::table('source_probe_requests')
            ->where(function ($q) {
                $q->whereIn('status', ['pending', 'running'])
                    ->orWhere(fn ($qq) => $qq->where('status', 'done')->where('updated_at', '>', now()->subHour()));
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        $existing = $host === ''
            ? null
            : $candidates->first(fn ($r) => SourceHost::host((string) $r->listing_url) === $host);
        if ($existing !== null) {
            return response()->json(['data' => [
                'id' => (int) $existing->id,
                'status' => $existing->status,
                'reused' => true,
            ]]);
        }

        $id = DB::table('source_probe_requests')->insertGetId([
            'listing_url' => $url,
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

        // хосты, уже ставшие источником — их заявки в ленте не нужны (жалоба
        // «разведка показывается, хотя источник уже добавлен»)
        $onboardedHosts = DB::table('source_profiles')
            ->pluck('listing_url')
            ->map(fn ($u) => SourceHost::host((string) $u))
            ->filter()
            ->unique();

        // onboarded — терминальный статус (проставляется в createProfile), в
        // ленту не идёт; берём с запасом для схлопывания по хосту
        $rows = DB::table('source_probe_requests')
            ->where('status', '!=', 'onboarded')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // схлопываем по origin-host: одна заявка на сайт. Приоритет статуса:
        // активная > done > failed (failed-only-хост оставляем — можно
        // перезапустить), тай-брейк по свежести (id).
        $rank = ['running' => 3, 'pending' => 3, 'done' => 2, 'failed' => 1];
        $byHost = [];
        foreach ($rows as $r) {
            $host = SourceHost::host((string) $r->listing_url);
            if ($host === '' || $onboardedHosts->contains($host)) {
                continue;
            }
            $cur = $byHost[$host] ?? null;
            $better = $cur === null
                || ($rank[$r->status] ?? 0) > ($rank[$cur->status] ?? 0)
                || (($rank[$r->status] ?? 0) === ($rank[$cur->status] ?? 0) && $r->id > $cur->id);
            if ($better) {
                $byHost[$host] = $r;
            }
        }

        $data = collect($byHost)
            ->sortByDesc('id')
            ->take(5)
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'listing_url' => $r->listing_url,
                'status' => $r->status,
                'error' => $r->error,
                'result' => is_string($r->result) ? json_decode($r->result, true) : $r->result,
            ])->values();

        return response()->json(['data' => $data]);
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
            // привязка к УЖЕ существующей площадке/сообществу вместо создания нового
            'community_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $req = DB::table('source_probe_requests')->where('id', $data['probe_request_id'])->first();
        abort_if($req === null || $req->status !== 'done', 422, 'Заявка не найдена или разведка не завершена');
        $result = is_string($req->result) ? (array) json_decode($req->result, true) : (array) $req->result;

        $cityId = DB::table('cities')->where('slug', $data['city_slug'])->value('id');
        abort_if(! $cityId, 422, "Город '{$data['city_slug']}' не найден");

        $origin = (string) ($result['origin'] ?? '');
        $host = SourceHost::host($origin);
        $slug = $data['slug'] ?? Str::of($host)->replace('.', '-')->lower()->toString();
        abort_if(DB::table('source_profiles')->where('slug', $slug)->exists(), 422, "Профиль '{$slug}' уже существует");

        if ($data['parse_mode'] === 'jsonld') {
            // выбранный кластер сужает регэксп до конкретной группы страниц;
            // без выбора — объединение всех positive-кластеров разведки
            $regex = ! empty($data['template'])
                ? $this->regexFromTemplate($origin, (string) $data['template'])
                : (string) ($result['suggested_regex'] ?? '');
            abort_if($regex === '', 422, 'Разведка не нашла Event JSON-LD — jsonld-режим невозможен, выбери llm_text с шаблоном');
        } else {
            $regex = $this->regexFromTemplate($origin, (string) $data['template']);
        }

        // если привязываем к существующему сообществу — оно должно жить и не
        // иметь другого сайт-источника (link network 3 уникален на сообщество)
        $existingCommunityId = isset($data['community_id']) ? (int) $data['community_id'] : null;
        $boundVia = $existingCommunityId !== null ? 'manual' : null;
        if ($existingCommunityId !== null) {
            abort_if(! DB::table('communities')->where('id', $existingCommunityId)->whereNull('deleted_at')->exists(),
                422, 'Сообщество не найдено');
            abort_if(DB::table('community_social_links')
                ->where('community_id', $existingCommunityId)->where('social_network_id', 3)->exists(),
                422, 'У этого сообщества уже есть сайт-источник');
        }

        // АВТО-связывание с существующей площадкой (консервативные правила):
        //  1) у сообщества уже записан URL с тем же доменом (сильный сигнал);
        //  2) точное совпадение нормализованного имени в том же городе.
        // Привязанное сообщество не должно иметь другого сайт-источника.
        if ($existingCommunityId === null) {
            $auto = $this->autoBindCommunity($host, $data['name'], (int) $cityId);
            if ($auto !== null) {
                [$existingCommunityId, $boundVia] = $auto;
            }
        }

        DB::transaction(function () use ($data, $slug, $regex, $req, $result, $cityId, $host, $existingCommunityId) {
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
            $communityId = $existingCommunityId
                ?? DB::table('communities')
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

            // терминальный статус заявки: сайт стал источником → в ленте
            // «Недавних разведок» не показываем (index фильтрует onboarded)
            DB::table('source_probe_requests')
                ->where('id', $req->id)
                ->update(['status' => 'onboarded', 'updated_at' => now()]);
        });

        $finalCommunityId = $existingCommunityId
            ?? (int) DB::table('community_social_links')
                ->where('social_network_id', 3)->where('external_community_id', $slug)->value('community_id');
        $communityName = (string) DB::table('communities')->where('id', $finalCommunityId)->value('name');

        Log::info('admin:source-probe:profile-created', [
            'actor_id' => $request->user()?->id,
            'slug' => $slug,
            'parse_mode' => $data['parse_mode'],
            'community_id' => $finalCommunityId,
            'bound_via' => $boundVia ?? 'created',
        ]);

        return response()->json(['data' => [
            'slug' => $slug,
            'enabled' => false,
            'community_id' => $finalCommunityId,
            'community_name' => $communityName,
            'bound_via' => $boundVia ?? 'created', // manual|url_host|name|created
        ]], 201);
    }

    /**
     * Авто-привязка к существующему сообществу: по домену в записанных URL
     * (links.url / communities.site_url-подобные поля не трогаем — links
     * достаточно) либо по точному нормализованному имени в городе.
     *
     * @return array{int, string}|null [community_id, via]
     */
    private function autoBindCommunity(string $host, string $name, int $cityId): ?array
    {
        // $host уже канонизирован в createProfile (SourceHost::host). Раньше
        // здесь был ltrim($host, 'w.') — резал ведущие w/точки как набор
        // символов ('weekend.ru' → 'eekend.ru') → промах авто-биндинга и дубль.
        $hostLike = '%'.str_replace(['%', '_'], '', $host).'%';

        // 1) домен уже записан у сообщества (любая сеть, чаще всего url линка)
        $byUrl = DB::table('community_social_links as l')
            ->join('communities as c', 'c.id', '=', 'l.community_id')
            ->whereNull('c.deleted_at')
            ->where('c.city_id', $cityId)
            ->where('l.url', 'ILIKE', $hostLike)
            ->value('l.community_id');
        if ($byUrl !== null && $this->freeOfSiteLink((int) $byUrl)) {
            return [(int) $byUrl, 'url_host'];
        }

        // 2) точное имя (без регистра/кавычек/лишних пробелов) в том же городе
        $norm = mb_strtolower(trim(preg_replace('~[«»"\'\s]+~u', ' ', $name)));
        $byName = DB::table('communities')
            ->whereNull('deleted_at')
            ->where('city_id', $cityId)
            ->whereRaw("lower(trim(regexp_replace(name, '[«»\"'']+', '', 'g'))) = ?", [trim(str_replace('  ', ' ', $norm))])
            ->value('id');
        if ($byName !== null && $this->freeOfSiteLink((int) $byName)) {
            return [(int) $byName, 'name'];
        }

        return null;
    }

    private function freeOfSiteLink(int $communityId): bool
    {
        return ! DB::table('community_social_links')
            ->where('community_id', $communityId)->where('social_network_id', 3)->exists();
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
