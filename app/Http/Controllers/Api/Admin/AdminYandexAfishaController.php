<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Sources\ScanYandexAfishaRequest;
use App\Http\Requests\Admin\Sources\UpdateYandexAfishaConfigRequest;
use App\Models\SourceConfig;
use App\Models\SourceRun;
use App\Services\Sources\YandexAfishaScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Управление источником Я.Афиша из админки (ТОЛЬКО суперадмин — route-группа
 * role:superadmin + defense-in-depth в FormRequest). Бэкенд страницы /sources/yandex.
 *
 * Пишет в source_configs (parser читает свежим, см. YandexAfishaConfigRepository).
 * Секреты (UA/headless/таймауты) тут не управляются — остаются в env.
 */
class AdminYandexAfishaController extends Controller
{
    private const SOURCE = 'yandex_afisha';

    public function config(): JsonResponse
    {
        $rows = SourceConfig::query()
            ->where('source_slug', self::SOURCE)
            ->orderBy('city_slug')
            ->get();

        return response()->json([
            'data' => [
                'source_slug' => self::SOURCE,
                'known_sections' => UpdateYandexAfishaConfigRequest::KNOWN_SECTIONS,
                'cities' => $rows->map(fn (SourceConfig $c) => $this->serializeConfig($c))->values()->all(),
            ],
        ]);
    }

    public function updateConfig(UpdateYandexAfishaConfigRequest $request): JsonResponse
    {
        $data = $request->validated();
        $city = (string) $data['city_slug'];

        $config = SourceConfig::firstOrNew([
            'source_slug' => self::SOURCE,
            'city_slug' => $city,
        ]);

        foreach (['enabled', 'json_ld_bypass_enabled', 'listing_limit_per_run', 'listing_limit_per_section'] as $field) {
            if (array_key_exists($field, $data)) {
                $config->{$field} = $data[$field];
            }
        }

        if (array_key_exists('sections', $data)) {
            // Нормализуем к [{slug, enabled}] — только whitelist-slug'и (валидатор),
            // taxonomy per-event парсер берёт из json_ld @type, не отсюда.
            $config->sections = array_values(array_map(static fn (array $s): array => [
                'slug' => $s['slug'],
                'enabled' => (bool) ($s['enabled'] ?? true),
            ], $data['sections']));
        }

        $config->save();

        Log::info('admin:yandex-afisha:config-updated', [
            'actor_id' => $request->user()?->id,
            'city_slug' => $city,
            'enabled' => (bool) $config->enabled,
            'sections' => array_column($config->sections ?? [], 'slug'),
        ]);

        return response()->json(['data' => $this->serializeConfig($config->fresh())]);
    }

    public function status(Request $request): JsonResponse
    {
        $city = trim((string) $request->query('city_slug', ''));

        $runsQuery = SourceRun::query()->where('source_slug', self::SOURCE);
        if ($city !== '') {
            $runsQuery->where('city_slug', $city);
        }

        $recent = (clone $runsQuery)->orderByDesc('started_at')->limit(10)->get();

        $posts48h = DB::table('context_posts')
            ->where('source', self::SOURCE)
            ->where('created_at', '>=', now()->subHours(48))
            ->count();

        return response()->json([
            'data' => [
                'source_slug' => self::SOURCE,
                'city_slug' => $city !== '' ? $city : null,
                'last_run' => $recent->first() ? $this->serializeRun($recent->first()) : null,
                'recent_runs' => $recent->map(fn (SourceRun $r) => $this->serializeRun($r))->values()->all(),
                'posts_48h' => $posts48h,
            ],
        ]);
    }

    /**
     * Разведка раздела «сейчас»: один headless-fetch листинга, подсчёт event-URL.
     * Синхронно (быстрый preview без detail-страниц) — суперадмин проверяет slug
     * до включения. Read-only.
     */
    public function scan(ScanYandexAfishaRequest $request, YandexAfishaScanner $scanner): JsonResponse
    {
        $data = $request->validated();
        $result = $scanner->scan($data['city_slug'], $data['section']);

        Log::info('admin:yandex-afisha:scan', [
            'actor_id' => $request->user()?->id,
            'city_slug' => $data['city_slug'],
            'section' => $data['section'],
            'urls_found' => $result['urls_found'],
            'ok' => $result['ok'],
        ]);

        return response()->json(['data' => $result]);
    }

    private function serializeConfig(SourceConfig $c): array
    {
        return [
            'id' => $c->id,
            'city_slug' => $c->city_slug,
            'enabled' => (bool) $c->enabled,
            'json_ld_bypass_enabled' => (bool) $c->json_ld_bypass_enabled,
            'listing_limit_per_run' => (int) $c->listing_limit_per_run,
            'listing_limit_per_section' => (int) $c->listing_limit_per_section,
            'sections' => is_array($c->sections) ? $c->sections : [],
            'run_requested_at' => optional($c->run_requested_at)->toIso8601String(),
            'updated_at' => optional($c->updated_at)->toIso8601String(),
        ];
    }

    private function serializeRun(SourceRun $r): array
    {
        return [
            'id' => $r->id,
            'city_slug' => $r->city_slug,
            'status' => $r->status,
            'started_at' => optional($r->started_at)->toIso8601String(),
            'finished_at' => optional($r->finished_at)->toIso8601String(),
            'urls_total' => (int) $r->urls_total,
            'posts_ok' => (int) $r->posts_ok,
            'posts_failed' => (int) $r->posts_failed,
            'error_text' => $r->error_text,
        ];
    }
}
