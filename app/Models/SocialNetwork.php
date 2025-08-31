<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialNetwork extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'url_mask',
    ];

    /**
     * Связь: social links сообществ
     */
    public function communitySocialLinks()
    {
        return $this->hasMany(CommunitySocialLink::class);
    }
}
