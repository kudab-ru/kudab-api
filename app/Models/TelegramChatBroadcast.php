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
        'idle_notified_at',
    ];

    protected $casts = [
        'enabled' => 'bool',
        'settings' => 'array',
        'last_run_at' => 'datetime',
        'last_preview_at' => 'datetime',
        'idle_notified_at' => 'datetime',
    ];

    /**
     * Связанный телеграм-чат (telegram.chats).
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'chat_id');
    }

    // ---- удобные геттеры/сеттеры поверх JSON settings ----

    /**
     * Сколько постов держать в ленте канала одновременно.
     *
     * Раньше действовало жёсткое «одно событие в полёте»: пока висела любая
     * незакрытая запись, канал пропускался. Из-за этого одна отравленная
     * запись остановила рассылку Воронежа на 33 дня, и из-за этого же нельзя
     * было собрать план на неделю вперёд.
     *
     * Считается ТОЛЬКО по событийным записям. Портреты площадок живут своим
     * недельным каденсом и считаются отдельно — иначе заполненная лента
     * заблокировала бы их навсегда.
     */
    public function getFeedLimitAttribute(): int
    {
        $settings = $this->settings ?? [];
        $raw = $settings['feed_limit'] ?? null;

        // Умолчание — семь: неделя вперёд, по посту в день. Раньше здесь
        // стояла единица (прежнее «одно событие в полёте»), она нужна была
        // только чтобы переход не менял поведение молча.
        if (! is_numeric($raw)) {
            return 7;
        }

        return max(1, min(31, (int) $raw));
    }

    public function setFeedLimitAttribute(int $limit): void
    {
        $settings = $this->settings ?? [];
        $settings['feed_limit'] = max(1, min(31, $limit));
        $this->settings = $settings;
    }

    public function getPeriodAttribute(): string
    {
        $settings = $this->settings ?? [];

        return (string) ($settings['period'] ?? 'off');
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

        return (string) ($settings['template_code'] ?? 'basic');
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
