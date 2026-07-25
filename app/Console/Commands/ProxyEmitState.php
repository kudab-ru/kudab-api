<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProxyConfig;
use Illuminate\Console\Command;

/**
 * Отдаёт РАСШИФРОВАННОЕ желаемое состояние прокси в stdout (JSON) — для
 * хост-applier'а (scripts/proxy-config-applier.py), который дёргает команду
 * через `docker exec kudab-api php artisan proxy:emit-state` (как root).
 *
 * СЕКРЕТ: в выводе есть subscription_url (plaintext). Команда безопасна ровно
 * потому, что достучаться до неё можно только из контейнера/хоста (docker exec,
 * root) — наружу (в API) секрет не уходит. Не логируем URL, не пишем в файлы.
 */
class ProxyEmitState extends Command
{
    protected $signature = 'proxy:emit-state';

    protected $description = 'Emit desired proxy state as JSON (incl. decrypted subscription) for the host applier';

    public function handle(): int
    {
        $c = ProxyConfig::query()->firstOrCreate(
            ['key' => 'default'],
            ['mode' => 'failover', 'enabled' => false],
        );

        $this->output->writeln(json_encode([
            'enabled' => (bool) $c->enabled,
            'mode' => $c->mode,
            'selected_server_index' => $c->selected_server_index,
            'subscription_url' => $c->subscription_url, // расшифровано cast'ом
            'apply_requested_at' => optional($c->apply_requested_at)->toIso8601String(),
            'applied_at' => optional($c->applied_at)->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
