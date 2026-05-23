<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal venue payload для embed'а в event-cards. На фронте — бейдж/ссылка
 * на полную venue-карточку. Без image/address/geo — это в WebVenueResource.
 */
class VenueBadgeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'   => (int) $this->id,
            'slug' => (string) ($this->slug ?? ''),
            'name' => (string) ($this->name ?? ''),
            'kind' => $this->kind !== null ? (string) $this->kind : null,
        ];
    }
}
