<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Proxy\UpdateProxyConfigRequest;
use App\Models\ProxyConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Админка управления VPN-прокси (xray), суперадмин (задача 2b).
 *
 * Api пишет ЖЕЛАЕМОЕ состояние в proxy_configs; ХОСТ-скрипт
 * scripts/proxy-config-applier.py (systemd-timer, root) читает его, перегенерит
 * xray-конфиг (scripts/gen-xray-balancer.py), валидирует и применяет с
 * авто-откатом, затем пишет статус обратно (artisan proxy:record-apply).
 *
 * БЕЗОПАСНОСТЬ: subscription_url — секрет, шифруется в БД (cast) и НИКОГДА не
 * отдаётся наружу; клиенту виден только флаг has_subscription. Все действия —
 * только суперадмин (route role:superadmin + FormRequest::authorize).
 */
class AdminProxyController extends Controller
{
    private function singleton(): ProxyConfig
    {
        return ProxyConfig::query()->firstOrCreate(
            ['key' => 'default'],
            ['mode' => 'failover', 'enabled' => false],
        );
    }

    /** Публичная (для UI) форма — БЕЗ секрета подписки. */
    private function present(ProxyConfig $c): array
    {
        $requested = $c->apply_requested_at;
        $applied = $c->applied_at;
        $pending = $requested !== null && ($applied === null || $applied->lt($requested));

        return [
            'mode' => $c->mode,
            'enabled' => (bool) $c->enabled,
            'selected_server_index' => $c->selected_server_index,
            // секрет не отдаём — только факт наличия
            'has_subscription' => $c->subscription_url !== null && $c->subscription_url !== '',
            'servers' => $c->servers_cache ?? [],
            'status' => $c->last_status ?? null,
            'apply_requested_at' => optional($requested)->toIso8601String(),
            'applied_at' => optional($applied)->toIso8601String(),
            'pending' => $pending,
        ];
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->present($this->singleton())]);
    }

    public function update(UpdateProxyConfigRequest $request): JsonResponse
    {
        $data = $request->validated();
        $c = $this->singleton();

        $changed = [];
        if (array_key_exists('subscription_url', $data)) {
            $c->subscription_url = ($data['subscription_url'] ?? '') !== '' ? $data['subscription_url'] : null;
            $changed[] = 'subscription_url';
        }
        foreach (['mode', 'selected_server_index', 'enabled'] as $f) {
            if (array_key_exists($f, $data)) {
                $c->{$f} = $data[$f];
                $changed[] = $f;
            }
        }
        abort_if($changed === [], 422, 'Нечего обновлять');

        // любое изменение желаемого состояния = заявка на применение
        $c->apply_requested_at = now();
        $c->updated_by = $request->user()?->id;
        $c->save();

        // секрет не логируем — только имена изменённых полей
        Log::info('admin:proxy:update', [
            'actor_id' => $request->user()?->id,
            'fields' => $changed,
            'mode' => $c->mode,
            'enabled' => (bool) $c->enabled,
        ]);

        return response()->json(['data' => $this->present($c->refresh())]);
    }

    /** Форсировать повторное применение текущего желаемого состояния. */
    public function apply(Request $request): JsonResponse
    {
        $c = $this->singleton();
        $c->apply_requested_at = now();
        $c->updated_by = $request->user()?->id;
        $c->save();

        Log::info('admin:proxy:apply-requested', [
            'actor_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->present($c->refresh())]);
    }
}
