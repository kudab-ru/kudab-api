<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParsingStatus extends Model
{
    protected $fillable = [
        'community_social_link_id',
        'is_frozen',
        'frozen_reason',
        'unfreeze_at',
        'last_error',
        'last_error_code',
        'last_success_at',
        'total_failures',
        'retry_count',
    ];

    protected $casts = [
        'is_frozen'       => 'boolean',
        'unfreeze_at'     => 'datetime',
        'last_success_at' => 'datetime',
        'total_failures'  => 'integer',
        'retry_count'     => 'integer',
    ];

    public function socialLink(): BelongsTo
    {
        return $this->belongsTo(CommunitySocialLink::class, 'community_social_link_id');
    }
}
