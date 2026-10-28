<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Каток: крытый или уличный, с недельным расписанием массового катания, ценами и тем, как попасть.
 *
 * @property array<int, array{days: list<int>, label: string, times: list<string>}>|null $schedule
 * @property array<int, array{date: string, times: list<string>}>|null $sessions
 * @property array<int, array{date: string, note: string}>|null $closures
 */
class Rink extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'indoor' => 'boolean',
        'kids' => 'boolean',
        'hidden' => 'boolean',
        'lat' => 'float',
        'lon' => 'float',
        'opens_on' => 'date:Y-m-d',
        'checked_at' => 'datetime',
        'schedule' => 'array',
        'sessions' => 'array',
        'closures' => 'array',
        'prices' => 'array',
        'features' => 'array',
        'links' => 'array',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
