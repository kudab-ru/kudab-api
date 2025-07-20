<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InterestRelation extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_interest_id',
        'child_interest_id',
    ];

    /**
     * Родительский интерес
     */
    public function parent()
    {
        return $this->belongsTo(Interest::class, 'parent_interest_id');
    }

    /**
     * Дочерний интерес
     */
    public function child()
    {
        return $this->belongsTo(Interest::class, 'child_interest_id');
    }
}
