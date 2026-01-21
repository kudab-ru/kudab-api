<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $city = $this->relationLoaded('city') ? $this->getRelation('city') : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,

            'city' => $city ? [
                'id' => $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ] : null,

            'city_id' => $this->city_id,

            'street' => $this->street ?? null,
            'house' => $this->house ?? null,

            'avatar_url' => $this->avatar_url,
            'image_url' => $this->image_url ?? null,

            'last_checked_at' => $this->last_checked_at,
            'verification_status' => $this->verification_status,
            'is_verified' => (bool)($this->is_verified ?? false),
            'verification_meta' => $this->verification_meta,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
