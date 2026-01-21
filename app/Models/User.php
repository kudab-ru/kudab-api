<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'bio',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deleted_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Связь с Telegram
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function telegramUsers()
    {
        return $this->hasMany(TelegramUser::class);
    }

    /**
     * Интересы пользователя (M:N)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function interests()
    {
        return $this->belongsToMany(Interest::class, 'interest_user')
            ->withTimestamps();
    }

    /**
     * RSVP/Участие в событиях (M:N)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function attendingEvents()
    {
        return $this->belongsToMany(Event::class, 'event_attendees')
            ->withPivot('status', 'joined_at')
            ->withTimestamps();
    }

    /**
     * Взаимодействия с контентом (лайки, комментарии и др.)
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function contextInteractions()
    {
        return $this->hasMany(ContextInteraction::class);
    }
}
