<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'original_post_id',
        'community_id',
        'title',
        'start_time',
        'end_time',
        'location',
        'city',
        'address',
        'description',
        'status',
        'external_url',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    /**
     * Сообщество-организатор события
     */
    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * Исходный пост (если событие из парсинга)
     */
    public function originalPost()
    {
        return $this->belongsTo(ContextPost::class, 'original_post_id');
    }

    /**
     * Интересы события
     */
    public function interests()
    {
        return $this->belongsToMany(Interest::class, 'event_interest')
            ->withTimestamps();
    }

    /**
     * Участники события (RSVP)
     */
    public function attendees()
    {
        return $this->belongsToMany(User::class, 'event_attendees')
            ->withPivot('status', 'joined_at')
            ->withTimestamps();
    }
}
