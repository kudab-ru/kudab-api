<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramChatBroadcast extends Model
{
    protected $table = 'telegram.chat_broadcasts';

    protected $fillable = [
        'chat_id',
        'enabled',
        'settings',
        'last_run_at',
        'last_preview_at',
    ];

    protected $casts = [
        'enabled'         => 'bool',
        'settings'        => 'array',
        'last_run_at'     => 'datetime',
        'last_preview_at' => 'datetime',
    ];

    /**
     * Связанный телеграм-чат (telegram.chats).
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'chat_id');
    }

    // ---- удобные геттеры/сеттеры поверх JSON settings ----

    public function getPeriodAttribute(): string
    {
        $settings = $this->settings ?? [];

        return (string)($settings['period'] ?? 'off');
    }

    public function setPeriodAttribute(string $period): void
    {
        $settings = $this->settings ?? [];
        $settings['period'] = $period;
        $this->settings = $settings;
    }

    public function getTemplateCodeAttribute(): string
    {
        $settings = $this->settings ?? [];

        return (string)($settings['template_code'] ?? 'basic');
    }

    public function setTemplateCodeAttribute(string $templateCode): void
    {
        $settings = $this->settings ?? [];
        $settings['template_code'] = $templateCode;
        $this->settings = $settings;
    }

    public function items()
    {
        return $this->hasMany(TelegramChatBroadcastItem::class, 'broadcast_id');
    }
}
