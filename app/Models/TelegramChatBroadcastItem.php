<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Элемент очереди публикаций в телеграм-чат.
 */
class TelegramChatBroadcastItem extends Model
{
    use HasFactory;

    protected $table = 'telegram.chat_broadcast_items';

    protected $fillable = [
        'broadcast_id',
        'kind',
        'event_id',
        'venue_id',
        'caption',
        'photo_url',
        'photo_urls',
        'status',
        'planned_at',
        'publish_at',
        'posted_at',
        'error_message',
        'review_reviewer_telegram_id',
        'review_message_id',
        'review_deadline_at',
        'reviewed_at',
        'review_action',
        'claimed_at',
        'claim_token',
        'caption_source',
        'is_pinned',
        'is_off_grid',
        'text_requested_at',
        'text_fail_reason',
        'text_hint',
        'edited_at',
        'edited_fields',
        'digest_meta',
        'message_id',
        'clicks',
        'clicks_at',
        'reactions',
        'reactions_meta',
        'reactions_at',
    ];

    protected $casts = [
        'planned_at' => 'datetime',
        'publish_at' => 'datetime',
        'posted_at' => 'datetime',
        'review_deadline_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'claimed_at' => 'datetime',
        'text_requested_at' => 'datetime',
        'edited_at' => 'datetime',
        'edited_fields' => 'array',
        // Подборка: тема состава и текст модели (intro + hooks по event_id).
        'digest_meta' => 'array',
        'clicks_at' => 'datetime',
        // разбивка по эмодзи: [{"emoji":"🔥","count":4}]
        'reactions_meta' => 'array',
        'reactions_at' => 'datetime',
        'is_pinned' => 'bool',
        'is_off_grid' => 'bool',
        // null — собрать автоматически, массив — только эти картинки, [] — без картинок
        'photo_urls' => 'array',
    ];

    public const STATUS_PENDING = 'pending'; // создано, но ещё не запланировано

    public const STATUS_PLANNED = 'planned'; // стоит в очереди на отправку

    public const STATUS_POSTED = 'posted';  // успешно отправлено

    public const STATUS_SKIPPED = 'skipped'; // пропущено (дубль/устарело)

    public const STATUS_ERROR = 'error';   // была ошибка при отправке

    /** см. TelegramChatBroadcastService::withdrawPostedItem() */
    public const STATUS_WITHDRAWN = 'withdrawn'; // снято из канала вручную

    // статусы ревью в личке; status — varchar(32)
    public const STATUS_PENDING_REVIEW = 'pending_review'; // ждёт решения ревьюера в ЛС

    public const STATUS_APPROVED = 'approved';       // ревьюер одобрил

    public const STATUS_REJECTED = 'rejected';       // ревьюер отклонил

    public const STATUS_AUTO_APPROVED = 'auto_approved';  // авто-одобрено по таймауту

    public const KIND_EVENT = 'event';  // событие (рендерится ботом из шаблона)

    /** Откуда взялся текст поста: собран из шаблона или правили руками. */
    public const CAPTION_TEMPLATE = 'template';

    public const CAPTION_MANUAL = 'manual';

    /** Что мог поправить человек — словарь для edited_fields. */
    public const EDIT_CAPTION = 'caption';

    public const EDIT_PHOTOS = 'photos';

    public const EDIT_TIME = 'time';

    /**
     * Поля копятся: каждое остаётся ручным, пока его не снимет forgetEdit().
     *
     * @param  list<string>  $fields
     */
    public function markEdited(array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->edited_fields = array_values(array_unique(array_merge($this->edited_fields ?? [], $fields)));
        $this->edited_at = now();
    }

    public function forgetEdit(string $field): void
    {
        $left = array_values(array_diff($this->edited_fields ?? [], [$field]));
        $this->edited_fields = $left === [] ? null : $left;
        if ($left === []) {
            $this->edited_at = null;
        }
    }

    public const KIND_VENUE = 'venue';  // портрет площадки (готовый caption)

    public const KIND_DIGEST = 'digest'; // подборка недели: несколько событий, готовый caption

    /**
     * Виды записей с готовым caption и без события. Проверять по этому списку,
     * а не `kind !== venue`: иначе новая рубрика попадёт в события.
     *
     * @return list<string>
     */
    public static function readyCaptionKinds(): array
    {
        return [self::KIND_VENUE, self::KIND_DIGEST];
    }

    public function hasReadyCaption(): bool
    {
        return in_array($this->kind, self::readyCaptionKinds(), true);
    }

    /**
     * Тема состава подборки («koncerty»). Хранится: из событий её не вывести,
     * у части из них несколько первичных интересов.
     */
    public function digestTheme(): ?string
    {
        $v = trim((string) (($this->digest_meta['theme'] ?? '')));

        return $v === '' ? null : $v;
    }

    /** Подводка под шапкой подборки; null — пост выйдет без неё. */
    public function digestIntro(): ?string
    {
        $v = trim((string) (($this->digest_meta['intro'] ?? '')));

        return $v === '' ? null : $v;
    }

    /**
     * Строка подборки про событие. Ключ — id события, а не позиция: состав
     * может сдвинуться между заказом текста и отправкой.
     */
    public function digestHook(int $eventId): ?string
    {
        $v = trim((string) (($this->digest_meta['hooks'][(string) $eventId] ?? '')));

        return $v === '' ? null : $v;
    }

    public function hasDigestText(): bool
    {
        return $this->digestIntro() !== null
            || array_filter((array) ($this->digest_meta['hooks'] ?? [])) !== [];
    }

    /**
     * Состав, под который написан текст; пишется вместе с текстом
     * (DigestDescribeCommand::store в kudab-parser, правка подводки в админке).
     * Пусто — текста ни под какой состав нет.
     *
     * @return list<int>
     */
    public function digestTextRoster(): array
    {
        $ids = array_values(array_map('intval', (array) ($this->digest_meta['roster'] ?? [])));
        sort($ids);

        return $ids;
    }

    /**
     * Текст написан под этот состав? Состав лежит в связи «пост → события», текст
     * в digest_meta, и только эта проверка не даёт уйти тексту про другие события.
     * Сравниваются множества: порядок строк меняется при переносе на другой день.
     *
     * @param  list<int>  $eventIds  нынешний состав
     */
    public function digestTextCoversRoster(array $eventIds): bool
    {
        if (! $this->hasDigestText()) {
            return false;
        }

        $now = array_values(array_unique(array_map('intval', $eventIds)));
        sort($now);

        return $now !== [] && $now === $this->digestTextRoster();
    }

    /**
     * @param  list<int>  $eventIds  нынешний состав
     */
    public function digestIntroFor(array $eventIds): ?string
    {
        return $this->digestTextCoversRoster($eventIds) ? $this->digestIntro() : null;
    }

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
