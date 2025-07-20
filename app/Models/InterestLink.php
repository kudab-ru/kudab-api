<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterestLink extends Model
{
    protected $table = 'interest_links';

    public $incrementing = false;

    protected $fillable = [
        'parent_type',
        'parent_id',
        'interest_id',
    ];

    /**
     * MorphTo-связь с объектом (context_post, event и др.)
     */
    public function parent()
    {
        return $this->morphTo();
    }

    /**
     * Интерес (категория/тег)
     */
    public function interest()
    {
        return $this->belongsTo(Interest::class);
    }
}
