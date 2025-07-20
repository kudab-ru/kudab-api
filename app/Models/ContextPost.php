<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContextPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'source',
        'author_id',
        'author_type',
        'community_id',
        'title',
        'text',
        'published_at',
        'status',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    /**
     * Сообщество, к которому относится пост (может быть null)
     */
    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * MorphTo связь с автором (user, community, external)
     */
    public function author()
    {
        return $this->morphTo(__FUNCTION__, 'author_type', 'author_id');
    }

    /**
     * Событие, связанное с этим постом (если есть)
     */
    public function event()
    {
        return $this->hasOne(Event::class, 'original_post_id');
    }

    /**
     * Вложения к посту
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'parent');
    }

    /**
     * Интересы поста (через interest_links)
     */
    public function interests()
    {
        return $this->morphToMany(Interest::class, 'parent', 'interest_links')
            ->withTimestamps();
    }
}
