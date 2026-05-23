<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка venue для каталога (`/web/venues?city_id=...`).
 *
 * `cover_image_url` — берётся через subquery в VenuesController (A4(a)
 * proxy: первый event на этом venue → первая картинка через
 * EventSource.images). Поле приходит в SELECT как `cover_image_url`.
 * Если у venue нет events с картинками — null.
 */
class WebVenueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => (int) $this->id,
            'slug'            => (string) ($this->slug ?? ''),
            'name'            => (string) ($this->name ?? ''),
            'kind'            => $this->kind !== null ? (string) $this->kind : null,
            'address'         => $this->address !== null ? (string) $this->address : null,
            'city_id'         => (int) ($this->city_id ?? 0),
            'city_slug'       => $this->getAttribute('city_slug') ?: null,
            'lat'             => $this->latitude !== null ? (float) $this->latitude : null,
            'lng'             => $this->longitude !== null ? (float) $this->longitude : null,
            'events_count'    => (int) ($this->getAttribute('events_count') ?? 0),
            'cover_image_url' => $this->getAttribute('cover_image_url') ?: null,
        ];
    }
}
