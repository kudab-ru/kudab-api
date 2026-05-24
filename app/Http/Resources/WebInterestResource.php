<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebInterestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => (int) $this->id,
            'slug'          => (string) $this->slug,
            'name'          => (string) $this->name,
            'parent_id'     => $this->parent_id !== null ? (int) $this->parent_id : null,
            'parent_slug'   => $this->getAttribute('parent_slug') !== null
                ? (string) $this->getAttribute('parent_slug')
                : null,
            'events_count'  => (int) ($this->getAttribute('events_count') ?? 0),
        ];
    }
}
