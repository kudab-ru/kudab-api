<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramChat extends Model
{
    use HasFactory;

    protected $table = 'telegram.chats';

    protected $fillable = [
        'telegram_user_id',
        'telegram_chat_id',
        'chat_type',
        'title',
        'username',
        'is_active',
        'linked_at',
        'unlinked_at',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'linked_at'   => 'datetime',
        'unlinked_at' => 'datetime',
    ];

    /**
     * Владелец/инициатор привязки (запись из telegram.users).
     */
    public function owner()
    {
        return $this->belongsTo(TelegramUser::class, 'telegram_user_id');
    }

    public function city()
    {
        return $this->belongsTo(\App\Models\City::class);
    }
}
