<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $startAt = $this->start_time;
        if ($startAt instanceof CarbonInterface) {
            $startAt = $startAt->toISOString();
        }

        $endAt = $this->end_time;
        if ($endAt instanceof CarbonInterface) {
            $endAt = $endAt->toISOString();
        }

        $images = $this->images;
        if (is_string($images)) {
            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($images)) $images = [];

        return [
            'id'            => $this->id,
            'title'         => (string) ($this->title ?? ''),

            'city_slug'     => $this->getAttribute('city_slug') ?: null,
            'venue'         => null,
            'address'       => $this->address,

            'poster'        => $this->poster,
            'images'        => array_values($images),

            'lat'           => $this->latitude,
            'lng'           => $this->longitude,

            'external_url'  => $this->external_url,

            'start_at'      => $startAt,
            'end_at'        => $endAt,
            'start_date'    => $this->start_date,
            'time_precision'=> $this->time_precision,

            'price_status'  => $this->price_status,
            'price_min'     => $this->price_min,
            'price_max'     => $this->price_max,
            'price_text'    => $this->price_text,
            'price_url'     => $this->price_url,
            'free'          => ($this->price_status === 'free'),
        ];
    }
}
