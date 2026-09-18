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
        'text_requested_at',
        'text_hint',
        'edited_at',
        'edited_fields',
        'digest_meta',
        'message_id',
        'clicks',
        'clicks_at',
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
        'is_pinned' => 'bool',
        // NULL = собрать автоматически; массив = ровно эти картинки.
        'photo_urls' => 'array',
    ];

    public const STATUS_PENDING = 'pending'; // создано, но ещё не запланировано

    public const STATUS_PLANNED = 'planned'; // стоит в очереди на отправку

    public const STATUS_POSTED = 'posted';  // успешно отправлено

    public const STATUS_SKIPPED = 'skipped'; // пропущено (дубль/устарело)

    public const STATUS_ERROR = 'error';   // была ошибка при отправке

    /**
     * Пост сняли из канала руками — запись больше не считается вышедшей.
     *
     * Владелец удаляет сообщение в телеграме, а база об этом не узнаёт никак:
     * телеграм не сообщает об удалении поста. Пока запись числилась вышедшей,
     * она держала зазор до следующего поста, занимала день в ленте и навсегда
     * записывала своё событие в показанные — то есть один удалённый пост
     * молча съедал слот.
     */
    public const STATUS_WITHDRAWN = 'withdrawn'; // снято из канала вручную

    // P0.5 approve-in-DM (статусы ревью-гейта; лезут в status varchar(32)):
    public const STATUS_PENDING_REVIEW = 'pending_review'; // ждёт решения ревьюера в ЛС

    public const STATUS_APPROVED = 'approved';       // ревьюер одобрил

    public const STATUS_REJECTED = 'rejected';       // ревьюер отклонил

    public const STATUS_AUTO_APPROVED = 'auto_approved';  // авто-одобрено по таймауту

    // Тип поста в очереди:
    public const KIND_EVENT = 'event';  // событие (рендерится ботом из шаблона)

    /** Откуда взялся текст поста: собран из шаблона или правили руками. */
    public const CAPTION_TEMPLATE = 'template';

    public const CAPTION_MANUAL = 'manual';

    /** Что мог поправить человек — словарь для edited_fields. */
    public const EDIT_CAPTION = 'caption';

    public const EDIT_PHOTOS = 'photos';

    public const EDIT_TIME = 'time';

    /**
     * Запомнить ручную правку. Поля копятся: поправили текст вчера, время
     * сегодня — в следе оба, потому что оба по-прежнему не автоматические.
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

    /** Поле вернулось к автоматическому — след о нём снимаем. */
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
     * Виды записей, у которых текст уже готов и события в колонке нет.
     *
     * Такие идут по доставке одной веткой: «взять caption и картинки, отдать
     * боту». Спрашивать этот список, а не сравнивать с одним типом: проверка
     * вида `kind !== venue` молча зачисляет каждую новую рубрику в события, и
     * первая же запись подборки снималась бы с причиной «событие недоступно».
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
     * Тема, под которую собран состав подборки («koncerty»).
     *
     * Хранится, потому что вывести её из названных событий нельзя: у части
     * событий первичных интересов несколько, и три названных дали бы три
     * разные темы. А без темы не собрать ни заголовок, ни склонения, ни подвал.
     */
    public function digestTheme(): ?string
    {
        $v = trim((string) (($this->digest_meta['theme'] ?? '')));

        return $v === '' ? null : $v;
    }

    /** Подводка про эту неделю, написанная моделью вместо шаблонной. */
    public function digestIntro(): ?string
    {
        $v = trim((string) (($this->digest_meta['intro'] ?? '')));

        return $v === '' ? null : $v;
    }

    /**
     * Строка модели про названное событие.
     *
     * Ключ — id события, а не позиция: между заказом текста и отправкой состав
     * может сдвинуться, и строка обязана уехать вместе со своим событием.
     */
    public function digestHook(int $eventId): ?string
    {
        $v = trim((string) (($this->digest_meta['hooks'][(string) $eventId] ?? '')));

        return $v === '' ? null : $v;
    }

    /** Текст подборки уже написан — есть подводка или хотя бы одна строка. */
    public function hasDigestText(): bool
    {
        return $this->digestIntro() !== null
            || array_filter((array) ($this->digest_meta['hooks'] ?? [])) !== [];
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
