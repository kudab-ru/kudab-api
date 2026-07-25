<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProxyConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Хост-applier пишет РЕЗУЛЬТАТ применения обратно в proxy_configs через
 * `docker exec kudab-api php artisan proxy:record-apply --payload=<base64-json> [--applied]`.
 *
 * --payload = base64(JSON) со статусом: {ok, message, active_server, tg_probe,
 * checked_at?, servers?:[{index,host,port,name,security}]}. servers (без секретов)
 * идёт в servers_cache для UI. --applied ставит applied_at=now() (успешное
 * применение желаемого состояния). base64 — чтобы не мучиться с экранированием
 * JSON в shell/docker exec.
 */
class ProxyRecordApply extends Command
{
    protected $signature = 'proxy:record-apply {--payload= : base64-encoded JSON status} {--applied : mark desired state as applied (applied_at=now)}';

    protected $description = 'Record proxy apply result/health from the host applier';

    public function handle(): int
    {
        $raw = (string) $this->option('payload');
        $decoded = $raw !== '' ? json_decode((string) base64_decode($raw, true), true) : [];
        if (! is_array($decoded)) {
            $this->error('bad --payload (not base64 JSON)');

            return self::FAILURE;
        }

        $c = ProxyConfig::query()->firstOrCreate(
            ['key' => 'default'],
            ['mode' => 'failover', 'enabled' => false],
        );

        $servers = $decoded['servers'] ?? null;
        unset($decoded['servers']);

        $decoded['checked_at'] = $decoded['checked_at'] ?? now()->toIso8601String();
        $c->last_status = $decoded;
        if (is_array($servers)) {
            $c->servers_cache = $servers;
        }
        if ($this->option('applied')) {
            $c->applied_at = now();
        }
        $c->save();

        Log::info('admin:proxy:apply-recorded', [
            'ok' => $decoded['ok'] ?? null,
            'active_server' => $decoded['active_server'] ?? null,
            'applied' => (bool) $this->option('applied'),
        ]);

        return self::SUCCESS;
    }
}
