<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventInterest extends Model
{
    protected $table = 'event_interest';

    protected $fillable = [
        'event_id',
        'interest_id',
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
     * Связь с интересом
     */
    public function interest()
    {
        return $this->belongsTo(Interest::class);
    }
}
