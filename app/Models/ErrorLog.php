<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorLog extends Model
{
    protected $fillable = [
        'type',
        'community_id',
        'community_social_link_id',
        'job',
        'error_text',
        'error_code',
        'logged_at',
        'resolved',
        'meta',
    ];

    public $timestamps = true;

    protected $casts = [
        'logged_at' => 'datetime',
        'resolved' => 'boolean',
        'meta' => 'array',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}
