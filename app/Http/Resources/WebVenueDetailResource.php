<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Детальная карточка venue (`/web/venues/{id}`).
 *
 * Поверх WebVenueResource: street/house/fias, description, future_events
 * (top-N грядущих ивентов на этом venue через тот же WebEventResource
 * что в каталоге).
 */
class WebVenueDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => (int) $this->id,
            'slug'            => (string) ($this->slug ?? ''),
            'name'            => (string) ($this->name ?? ''),
            'kind'            => $this->kind !== null ? (string) $this->kind : null,
            'description'     => $this->description !== null ? (string) $this->description : null,

            'address'         => $this->address !== null ? (string) $this->address : null,
            'street'          => $this->street !== null ? (string) $this->street : null,
            'house'           => $this->house !== null ? (string) $this->house : null,
            'house_fias_id'   => $this->house_fias_id !== null ? (string) $this->house_fias_id : null,

            'city' => $this->whenLoaded('city', fn () => [
                'id'   => (int) $this->city->id,
                'name' => (string) $this->city->name,
                'slug' => (string) ($this->city->slug ?? ''),
            ]),

            'lat'             => $this->latitude !== null ? (float) $this->latitude : null,
            'lng'             => $this->longitude !== null ? (float) $this->longitude : null,

            'cover_image_url' => $this->getAttribute('cover_image_url') ?: null,
            'avatar_url'      => $this->avatar_url !== null ? (string) $this->avatar_url : null,

            'events_count'    => (int) ($this->getAttribute('events_count') ?? 0),

            // «Здесь бывает» — топ interest-тегов по всей истории событий
            // площадки. Пустой массив = профиля нет (мало истории), фронт
            // блок не рисует. Считается в VenuesController::genreProfile().
            'genre_profile'   => array_values($this->getAttribute('genre_profile') ?? []),
            // future_events идут через отдельный /web/events?venue_id=...
            // фронт сам делает второй запрос; в venue-detail держать список
            // events избыточно (rebroadcast той же sql-логики event-репо).
        ];
    }
}
