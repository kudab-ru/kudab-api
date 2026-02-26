<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunitySocialLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_id',
        'social_network_id',
        'external_community_id',
        'url',
        'status',
    ];

    /**
     * Связь с сообществом
     */
    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * Связь с соцсетью
     */
    public function socialNetwork()
    {
        return $this->belongsTo(SocialNetwork::class);
    }
}
