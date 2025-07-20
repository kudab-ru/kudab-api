<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'telegram_id',
        'telegram_username',
        'first_name',
        'last_name',
        'language_code',
        'chat_id',
        'is_bot',
        'registered_at',
        'last_active',
    ];

    protected $casts = [
        'is_bot' => 'boolean',
        'registered_at' => 'datetime',
        'last_active' => 'datetime',
    ];

    /**
     * Связь с основной учеткой пользователя (user может быть null).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
