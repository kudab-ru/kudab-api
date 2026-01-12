<?php

namespace App\Http\Resources\Web;

use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'   => (int) $this->id,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
        ];
    }
}
