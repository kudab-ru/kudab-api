<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Единый рубильник по городам (kudab-api):
 * - ok: city_id задан и cities.status = active
 * - needs_city: city_id не задан
 * - city_inactive: city_id задан, но status != active (disabled/limited)
 */
final class CityGate
{
    public const OK = 'ok';
    public const NEEDS_CITY = 'needs_city';
    public const CITY_INACTIVE = 'city_inactive';

    public static function stateByCityId(?int $cityId): string
    {
        if (!$cityId) {
            return self::NEEDS_CITY;
        }

        $status = DB::table('cities')->where('id', (int) $cityId)->value('status');
        return ((string) $status === 'active') ? self::OK : self::CITY_INACTIVE;
    }
}
