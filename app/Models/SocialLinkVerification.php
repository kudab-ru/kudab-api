<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialLinkVerification extends Model
{
    protected $table = 'social_link_verifications';

    protected $fillable = [
        'community_id',
        'community_social_link_id',
        'social_network_id',
        'checked_at',
        'status',
        'latency_ms',
        'model',
        'prompt_version',
        'error_code',
        'error_message',
        'is_active',
        'has_events_posts',
        'activity_score',
        'events_score',
        'kind',
        'has_fixed_place',
        'hq_city',
        'hq_street',
        'hq_house',
        'hq_confidence',
        'examples',
        'events_locations',
        'raw',
    ];

    protected $casts = [
        'checked_at'       => 'datetime',
        'is_active'        => 'bool',
        'has_events_posts' => 'bool',
        'activity_score'   => 'float',
        'events_score'     => 'float',
        'has_fixed_place'  => 'bool',
        'hq_confidence'    => 'float',
        'examples'         => 'array',
        'events_locations' => 'array',
        'raw'              => 'array',
    ];

    public function link()
    {
        return $this->belongsTo(CommunitySocialLink::class, 'community_social_link_id');
    }

    public function community()
    {
        return $this->belongsTo(Community::class, 'community_id');
    }
}
