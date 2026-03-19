<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Event
 */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // У Event может быть колонка `city` (строка), которая конфликтует с relation `city()`.
        // Поэтому отношения читаем только через getRelation(), НЕ через $this->city.

        $city = null;
        if ($this->resource->relationLoaded('city')) {
            $cityModel = $this->resource->getRelation('city');
            if ($cityModel) {
                $city = [
                    'id' => $cityModel->id,
                    'name' => (string)$cityModel->name,
                    'slug' => (string)$cityModel->slug,
                ];
            }
        }

        $community = null;
        if ($this->resource->relationLoaded('community')) {
            $communityModel = $this->resource->getRelation('community');

            if ($communityModel) {
                // если подгружен community.city — берём название города из него
                $communityCityName = null;
                if ($communityModel->relationLoaded('city') && $communityModel->getRelation('city')) {
                    $communityCityName = (string)$communityModel->getRelation('city')->name;
                }

                $community = [
                    'id' => $communityModel->id,
                    'name' => (string)$communityModel->name,
                    'city' => $communityCityName,
                    'avatar_url' => $communityModel->avatar_url,
                ];
            }
        }

        $interests = [];
        if ($this->resource->relationLoaded('interests')) {
            $interests = $this->resource->getRelation('interests')
                ->map(fn ($i) => ['id' => $i->id, 'name' => (string)$i->name])
                ->values()
                ->all();
        }

        return [
            'id' => $this->id,
            'original_post_id' => $this->original_post_id,
            'community_id' => $this->community_id,

            'title' => $this->title,
            'description' => $this->description,

            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'start_date' => $this->start_date,
            'time_precision' => $this->time_precision,
            'time_text' => $this->time_text,
            'timezone' => $this->timezone,

            // city как объект (из relation), плюс city_id отдельно
            'city' => $city,
            'city_id' => $this->city_id,

            'address' => $this->address,
            'status' => $this->status,
            'external_url' => $this->external_url,

            'location' => $this->location,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'lat_round' => $this->lat_round,
            'lon_round' => $this->lon_round,
            'dedup_key' => $this->dedup_key,
            'house_fias_id' => $this->house_fias_id,

            'price_status' => $this->price_status,
            'price_min' => $this->price_min,
            'price_max' => $this->price_max,
            'price_currency' => $this->price_currency,
            'price_text' => $this->price_text,
            'price_url' => $this->price_url,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,

            'community' => $community,
            'interests' => $interests,
        ];
    }
}
