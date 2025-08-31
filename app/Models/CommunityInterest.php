<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunityInterest extends Model
{
    protected $table = 'community_interest';

    protected $fillable = [
        'community_id',
        'interest_id',
    ];

    public $incrementing = false;

    /**
     * Связь с сообществом
     */
    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * Связь с интересом
     */
    public function interest()
    {
        return $this->belongsTo(Interest::class);
    }
}
