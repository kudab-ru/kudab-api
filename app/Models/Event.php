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

    /** Интересы события */
    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'event_interest')
            ->withTimestamps();
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
}
