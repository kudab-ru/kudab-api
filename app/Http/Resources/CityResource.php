<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'slug'         => $this->slug,
            'name'         => $this->name,
            'country_code' => $this->country_code,
            'status'       => $this->status,
            'latitude'     => (float) $this->latitude,
            'longitude'    => (float) $this->longitude,
            // distance_m будет только если селектировали его в репозитории
            'distance_m'   => $this->when(isset($this->distance_m), (int) round($this->distance_m)),
        ];
    }
}
