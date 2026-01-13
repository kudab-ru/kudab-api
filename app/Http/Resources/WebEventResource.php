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
            'id'             => (int) $this->id,
            'title'          => (string) ($this->title ?? ''),

            'city_slug'      => $this->getAttribute('city_slug') ?: null,
            'venue'          => null,
            'address'        => $this->address !== null ? (string) $this->address : null,

            'poster'         => $this->poster !== null ? (string) $this->poster : null,
            'images'         => array_values(array_filter($images, fn($u) => is_string($u) && $u !== '')),

            'lat'            => $this->latitude !== null ? (float) $this->latitude : null,
            'lng'            => $this->longitude !== null ? (float) $this->longitude : null,

            'external_url'   => $this->external_url !== null ? (string) $this->external_url : null,

            'start_at'       => $startAt,
            'end_at'         => $endAt,
            'start_date'     => $this->start_date ? substr((string) $this->start_date, 0, 10) : null,
            'time_precision' => (string) ($this->time_precision ?? 'datetime'),

            'price_status'   => (string) ($this->price_status ?? 'unknown'),
            'price_min'      => $this->price_min !== null ? (int) $this->price_min : null,
            'price_max'      => $this->price_max !== null ? (int) $this->price_max : null,
            'price_text'     => $this->price_text !== null ? (string) $this->price_text : null,
            'price_url'      => $this->price_url !== null ? (string) $this->price_url : null,

            'free'           => ($this->price_status === 'free')
                || ((int)($this->price_min ?? -1) === 0 && $this->price_max === null),
        ];
    }
}
