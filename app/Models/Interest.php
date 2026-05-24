<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Interest extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
    ];

    /**
     * Дерево интересов (self-relation)
     */
    public function parent()
    {
        return $this->belongsTo(Interest::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Interest::class, 'parent_id');
    }

    /**
     * Пользователи, подписанные на интерес
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'interest_user')
            ->withTimestamps();
    }
}
