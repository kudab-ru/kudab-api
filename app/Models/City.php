<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\HasOne;

class City extends Model
{
    protected $fillable = ['name', 'country_code'];

    protected $casts = [
        'latitude'  => 'float',
        'longitude' => 'float',
    ];

    public function telegramChannel(): HasOne
    {
        return $this->hasOne(TelegramCityChannel::class, 'city_id');
    }

    /** Связи */
    public function communities(): HasMany
    {
        return $this->hasMany(\App\Models\Community::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(\App\Models\Event::class);
    }

    /**
     * Создать город и сразу записать POINT(lon lat) c SRID=4326
     */
    public static function createWithPoint(string $name, ?string $country, float $lat, float $lon): self
    {
        $city = static::create([
            'name'         => $name,
            'country_code' => $country,
        ]);

        $city->setPoint($lat, $lon);
        return $city->refresh(); // подтянуть latitude/longitude из STORED колонок
    }

    /**
     * Обновить геоточку. Без пакетов — через сырой UPDATE с биндингами.
     */
    public function setPoint(float $lat, float $lon): void
    {
        DB::update(
            'UPDATE cities
             SET location = ST_SetSRID(ST_Point(?, ?), 4326),
                 updated_at = NOW()
             WHERE id = ?',
            [$lon, $lat, $this->id]
        );
    }

    /**
     * Поиск по близости к координате (радиус в метрах)
     */
    public function scopeNearCoordinates($q, float $lat, float $lon, int $radiusMeters = 10000)
    {
        return $q
            ->whereRaw(
                'ST_DWithin(location, ST_SetSRID(ST_Point(?, ?), 4326), ?)',
                [$lon, $lat, $radiusMeters]
            )
            ->orderByRaw(
                'ST_Distance(location, ST_SetSRID(ST_Point(?, ?), 4326)) ASC',
                [$lon, $lat]
            );
    }

    /** Удобные фильтры */
    public function scopeByName($q, string $term)
    {
        return $q->whereRaw('lower(name) like lower(?)', ['%'.$term.'%']);
    }

    public function scopeCountry($q, ?string $code)
    {
        return $code ? $q->where('country_code', $code) : $q;
    }
}
