<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Community extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'source',
        'avatar_url',
        'external_id',
    ];

    /**
     * Связи: social_links, interests, events
     */
    public function socialLinks()
    {
        return $this->hasMany(CommunitySocialLink::class);
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
