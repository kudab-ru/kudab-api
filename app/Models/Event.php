<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Заполняемые поля (ручной апдейт/создание).
     * 'location', 'dedup_key', 'lat_round', 'lon_round' — вне fillable (служебные/генерятся SQL).
     */
    protected $fillable = [
        'original_post_id',
        'community_id',
        'venue_id',
        'title',
        'start_time',
        'end_time',
        'city',
        'address',
        'description',
        'status',
        'external_url',
        'latitude',
        'longitude',
        'house_fias_id',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
        'start_date' => 'date:Y-m-d',
        'latitude'   => 'float',
        'longitude'  => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /* ==================== Relations ==================== */

    /** Сообщество-организатор */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** Исходный пост (если событие из парсинга) */
    public function originalPost(): BelongsTo
    {
        return $this->belongsTo(ContextPost::class, 'original_post_id');
    }

    /** Интересы события — по rank (0 = главная тема, порядок тэггера парсера) */
    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'event_interest')
            ->withTimestamps()
            ->orderBy('event_interest.rank')
            ->orderBy('interests.id');
    }

    /** Участники (RSVP) */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_attendees')
            ->withPivot('status', 'joined_at')
            ->withTimestamps();
    }

    /** Источники (VK/ТГ/сайты) */
    public function sources(): HasMany
    {
        return $this->hasMany(EventSource::class)->orderByDesc('published_at');
    }

    /* ==================== Scopes ==================== */

    public function scopeActive($q)
    {
        return $q->where('status', 'active')->whereNull('deleted_at');
    }

    public function scopeUpcoming($q)
    {
        // небольшой «хвост назад» — чтобы не терять начавшиеся час назад
        return $q->where('start_time', '>=', now()->subHour());
    }

    /* ============ Web-видимость (паритет с паблик-лентой) ============ */

    /**
     * Blacklist-гейт паблик-выдачи: скрываем событие, только если есть хотя бы
     * один source с black-ссылкой И нет ни одного не-black source (включая
     * source без social_link_id).
     *
     * Единственный источник правды этого условия. Веб-лента применяет его
     * через EventRepository::excludeBlacklistedSources → этот scope.
     * Требует alias таблицы `events` в запросе (без переименования).
     */
    public function scopeWebNotBlacklisted($q)
    {
        return $q->whereRaw("
        NOT (
            EXISTS (
                SELECT 1
                FROM event_sources es
                JOIN community_social_links csl ON csl.id = es.social_link_id
                WHERE es.event_id = events.id
                  AND csl.status = 'black'
            )
            AND NOT EXISTS (
                SELECT 1
                FROM event_sources es2
                LEFT JOIN community_social_links csl2 ON csl2.id = es2.social_link_id
                WHERE es2.event_id = events.id
                  AND (
                    es2.social_link_id IS NULL
                    OR COALESCE(csl2.status, 'active') <> 'black'
                  )
            )
        )
    ");
    }

    /**
     * Дефолтное сужение «общегородская развлекательная лента»: без
     * kids/family-аудитории и вне-форматных content_kind. NULL-значения НЕ
     * скрываем (legacy events / пропуски LLM — backwards-compat).
     *
     * Синхронно с EventRepository::applyMainFeedTaxonomyFilter (он делегирует
     * сюда; include_all-override остаётся на стороне репозитория).
     */
    public function scopeWebMainFeedTaxonomy($q)
    {
        $q->where(function ($w) {
            $w->whereNull('events.audience')
                ->orWhereNotIn('events.audience', ['kids', 'family']);
        });

        $q->where(function ($w) {
            $w->whereNull('events.content_kind')
                ->orWhereIn('events.content_kind', [
                    'entertainment', 'culture', 'education', 'sport', 'civic',
                ]);
        });

        return $q;
    }

    /**
     * «Видимое в вебе» событие — тот же статус-скоуп, что паблик-выдача
     * /api/web/events (EventRepository::paginateUpcomingWeb): не удалено +
     * город active + не blacklisted + дефолтная таксономия ленты.
     *
     * БЕЗ временно́го окна — границу (upcoming / lookback) задаёт вызывающий.
     * include_all-override здесь не поддерживается (это фича query-string
     * ленты, не «видимости по умолчанию»).
     */
    public function scopeVisibleWeb($q)
    {
        return $q->whereNull('events.deleted_at')
            ->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('cities')
                    ->whereColumn('cities.id', 'events.city_id')
                    ->where('cities.status', 'active');
            })
            ->webNotBlacklisted()
            ->webMainFeedTaxonomy();
    }

    /* ==================== Helpers ==================== */

    /**
     * Агрегированные изображения из связанных источников (до N штук, uniq).
     * Без лишних запросов, если relation 'sources' уже загружен.
     */
    public function images(int $limit = 6): array
    {
        $sources = $this->relationLoaded('sources')
            ? $this->getRelation('sources')
            : $this->sources()->get(['images', 'published_at']);

        $out = [];
        foreach ($sources as $src) {
            $imgs = is_array($src->images ?? null) ? $src->images : [];
            foreach ($imgs as $u) {
                if ($u && !isset($out[$u])) {
                    $out[$u] = true;
                    if (count($out) >= $limit) {
                        return array_keys($out);
                    }
                }
            }
        }
        return array_keys($out);
    }

    /** Удобный аксессор одной обложки */
    public function poster(): ?string
    {
        $arr = $this->images(1);
        return $arr[0] ?? null;
    }

    public function broadcastItems()
    {
        return $this->hasMany(TelegramChatBroadcastItem::class, 'event_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function eventSources()
    {
        return $this->hasMany(\App\Models\EventSource::class, 'event_id');
    }
}
