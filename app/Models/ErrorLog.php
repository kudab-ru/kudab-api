<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    public $timestamps = true;

    protected $casts = [
        'logged_at' => 'datetime',
        'resolved' => 'boolean',
    ];
}
