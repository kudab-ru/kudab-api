<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VenueManager extends Model
{
    protected $fillable = ['venue_id', 'user_id', 'status', 'code', 'vk_url', 'confirmed_at'];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
