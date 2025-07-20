<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_type',
        'parent_id',
        'type',
        'url',
        'preview_url',
        'order',
    ];

    /**
     * MorphTo-связь с родителем (context_post, event и др.)
     */
    public function parent()
    {
        return $this->morphTo();
    }
}
