<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Venue — физическая площадка (театр, клуб, музей и т.п.).
 * Создаются: (1) PR2-backfill из communities.venue_host со street+house;
 *            (2) PR6 A2 promote-from-structured-meta (Я.Афиша JSON-LD).
 *
 * `latitude/longitude` — generated columns (ST_Y/ST_X over location).
 * Geo-операции через PostGIS на стороне SQL.
 */
class Venue extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'city_id',
        'name',
        'slug',
        'street',
        'house',
        'address',
        'house_fias_id',
        'kind',
        'description',
        'avatar_url',
        'cover_url',
        'status',
        'source_meta',
    ];

    protected $casts = [
        'source_meta' => 'array',
        'latitude'    => 'float',
        'longitude'   => 'float',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
        'deleted_at'  => 'datetime',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function communities(): HasMany
    {
        return $this->hasMany(Community::class);
    }

    public function scopeActive($q)
    {
        // Prefix table-name явно — иначе ambiguous с cities.status при JOIN'ах.
        return $q->where('venues.status', 'active')->whereNull('venues.deleted_at');
    }
}
