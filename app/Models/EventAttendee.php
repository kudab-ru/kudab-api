<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAttendee extends Model
{
    protected $table = 'event_attendees';

    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'joined_at',
    ];

    public $incrementing = false;

    /**
     * Связь с событием
     */
    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Связь с пользователем
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
