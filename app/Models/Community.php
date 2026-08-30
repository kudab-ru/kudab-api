<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Community extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'avatar_url',
        'image_url',
        'last_checked_at',
        'verification_status',
        'verification_meta',
        'city_id',
        'street',
        'house',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'verification_meta' => 'array',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function socialLinks()
    {
        return $this->hasMany(CommunitySocialLink::class);
    }

    /**
     * Посты, которые мы с этого сообщества прочитали. Нужны свежестью: на
     * странице площадки max(published_at) отвечает на вопрос «а вы вообще ещё
     * следите за этим местом» (см. VenuesController::show).
     *
     * ContextPost НЕ использует SoftDeletes, хотя колонка deleted_at в таблице
     * есть: гашение постов делает context:cleanup (посты старше 30 дней, не
     * породившие событий) — это TTL хранилища, и для свежести такие посты
     * считаются наравне с живыми. Кому нужно иное — фильтрует явно.
     */
    public function contextPosts()
    {
        return $this->hasMany(ContextPost::class);
    }

    public function interests()
    {
        return $this->belongsToMany(Interest::class, 'community_interest')
            ->withTimestamps();
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }
}
