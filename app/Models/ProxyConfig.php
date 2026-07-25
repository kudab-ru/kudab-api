<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Желаемое состояние VPN-прокси (xray), редактируемое суперадмином из админки (2b).
 * Одна строка (key='default'). Пишет только api; читает хост-applier.
 *
 * subscription_url — cast 'encrypted': в БД ciphertext, в приложении plaintext.
 * НИКОГДА не отдаётся в API-ответах (контроллер отдаёт только has_subscription).
 * Расшифровка вне api — только через artisan proxy:emit-state (docker exec, root).
 */
class ProxyConfig extends Model
{
    protected $fillable = [
        'key',
        'subscription_url',
        'mode',
        'selected_server_index',
        'enabled',
        'apply_requested_at',
        'applied_at',
        'last_status',
        'servers_cache',
        'updated_by',
    ];

    protected $casts = [
        'subscription_url'      => 'encrypted',
        'enabled'               => 'boolean',
        'selected_server_index' => 'integer',
        'apply_requested_at'    => 'datetime',
        'applied_at'            => 'datetime',
        'last_status'           => 'array',
        'servers_cache'         => 'array',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
    ];

    /** Скрываем секрет при любой сериализации модели (defense-in-depth). */
    protected $hidden = [
        'subscription_url',
    ];
}
