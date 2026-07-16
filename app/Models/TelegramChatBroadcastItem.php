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
        'kind',
        'event_id',
        'venue_id',
        'caption',
        'photo_url',
        'status',
        'planned_at',
        'posted_at',
        'error_message',
        'review_reviewer_telegram_id',
        'review_message_id',
        'review_deadline_at',
        'reviewed_at',
        'review_action',
        'claimed_at',
        'claim_token',
    ];

    protected $casts = [
        'planned_at' => 'datetime',
        'posted_at' => 'datetime',
        'review_deadline_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending'; // создано, но ещё не запланировано

    public const STATUS_PLANNED = 'planned'; // стоит в очереди на отправку

    public const STATUS_POSTED = 'posted';  // успешно отправлено

    public const STATUS_SKIPPED = 'skipped'; // пропущено (дубль/устарело)

    public const STATUS_ERROR = 'error';   // была ошибка при отправке

    // P0.5 approve-in-DM (статусы ревью-гейта; лезут в status varchar(32)):
    public const STATUS_PENDING_REVIEW = 'pending_review'; // ждёт решения ревьюера в ЛС

    public const STATUS_APPROVED = 'approved';       // ревьюер одобрил

    public const STATUS_REJECTED = 'rejected';       // ревьюер отклонил

    public const STATUS_AUTO_APPROVED = 'auto_approved';  // авто-одобрено по таймауту

    // Тип поста в очереди:
    public const KIND_EVENT = 'event';  // событие (рендерится ботом из шаблона)

    public const KIND_VENUE = 'venue';  // портрет площадки (готовый caption)

    /**
     * Настройки рассылки для чата.
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(TelegramChatBroadcast::class, 'broadcast_id');
    }

    /**
     * Событие, которое публикуем (для kind=event).
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    /**
     * Площадка портрета (для kind=venue).
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }
}
