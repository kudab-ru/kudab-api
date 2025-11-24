<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Элемент очереди публикаций в телеграм-чат.
 *
 * Связи:
 *  - broadcast() → TelegramChatBroadcast (настройки рассылки для чата)
 *  - event()     → Event (событие, которое публикуем)
 */
class TelegramChatBroadcastItem extends Model
{
    use HasFactory;

    /**
     * Полное имя таблицы с учётом схемы Postgres.
     */
    protected $table = 'telegram.chat_broadcast_items';

    protected $fillable = [
        'broadcast_id',
        'event_id',
        'status',
        'planned_at',
        'posted_at',
        'error_message',
    ];

    protected $casts = [
        'planned_at' => 'datetime',
        'posted_at'  => 'datetime',
    ];

    public const STATUS_PENDING = 'pending'; // создано, но ещё не запланировано
    public const STATUS_PLANNED = 'planned'; // стоит в очереди на отправку
    public const STATUS_POSTED  = 'posted';  // успешно отправлено
    public const STATUS_SKIPPED = 'skipped'; // пропущено (дубль/устарело)
    public const STATUS_ERROR   = 'error';   // была ошибка при отправке

    /**
     * Настройки рассылки для чата.
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(TelegramChatBroadcast::class, 'broadcast_id');
    }

    /**
     * Событие, которое публикуем.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
