<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_post_id' => $this->original_post_id,
            'community_id' => $this->community_id,
            'city_id' => $this->city_id,

            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'external_url' => $this->external_url,

            'address' => $this->address,

            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'start_date' => $this->start_date,

            'time_precision' => $this->time_precision,
            'time_text' => $this->time_text,
            'timezone' => $this->timezone,

            'price_status' => $this->price_status,
            'price_min' => $this->price_min,
            'price_max' => $this->price_max,
            'price_currency' => $this->price_currency,
            'price_text' => $this->price_text,
            'price_url' => $this->price_url,

            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'house_fias_id' => $this->house_fias_id,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,

            // relations (только если загружены)
            'city' => $this->whenLoaded('city', fn () => (new CityResource($this->city))->resolve()),
            'community' => $this->whenLoaded('community', fn () => (new CommunityMiniResource($this->community))->resolve()),
            'interests' => $this->whenLoaded('interests', fn () =>
            $this->interests->map(fn ($i) => (new InterestResource($i))->resolve())->values()->all()
            ),
        ];
    }
}
