<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Желаемое состояние VPN-прокси (xray), редактируемое суперадмином из админки
 * (задача 2b). Единственная строка (key='default').
 *
 * ПАТТЕРН (как source_configs): пишет ТОЛЬКО api (admin-эндпоинты). Читает —
 * ХОСТ-скрипт scripts/proxy-config-applier.py (systemd-timer, root): при
 * apply_requested_at > applied_at он перегенерирует /usr/local/etc/xray/config.json
 * через scripts/gen-xray-balancer.py (2a), валидирует `xray -test`, применяет с
 * бэкапом+авто-откатом и пишет статус/здоровье обратно (proxy:record-apply).
 *
 * СЕКРЕТ: subscription_url хранится ШИФРОВАННЫМ (Laravel Crypt, cast 'encrypted'
 * в модели). Api НИКОГДА не отдаёт его в ответах; расшифровка — только внутри
 * контейнера kudab-api (artisan proxy:emit-state, дёргается applier'ом по
 * docker exec как root). servers_cache/last_status — БЕЗ секретов (host/port/имя).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('proxy_configs')) {
            return;
        }

        Schema::create('proxy_configs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // singleton-адресация (firstOrCreate по key='default')
            $table->string('key', 32)->default('default');

            // ШИФРОВАННО (cast 'encrypted'); в БД — ciphertext, не отдаётся в API
            $table->text('subscription_url')->nullable();

            // failover = пул всех серверов подписки + balancer; single = один сервер
            $table->string('mode', 16)->default('failover');
            $table->unsignedInteger('selected_server_index')->nullable();

            // мастер-тумблер управления прокси из админки
            $table->boolean('enabled')->default(false);

            // admin бампает apply_requested_at; applier ставит applied_at при успехе
            $table->timestamp('apply_requested_at')->nullable();
            $table->timestamp('applied_at')->nullable();

            // {ok, message, active_server, tg_probe, checked_at} — пишет applier, БЕЗ секретов
            $table->jsonb('last_status')->nullable();
            // [{index, host, port, name, security}] — для UI, БЕЗ id/ключей
            $table->jsonb('servers_cache')->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->unique('key');
        });

        // стартовая строка: выключено, пока суперадмин не задаст подписку
        DB::table('proxy_configs')->insert([
            'key' => 'default',
            'mode' => 'failover',
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_configs');
    }
};
