<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebEventDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Важно: в репозитории ты “подмешиваешь” computed-атрибуты poster/images через hydrateImages().
        // Поэтому берём их как атрибуты, и только если нет — дефолты.
        $images = $this->getAttribute('images');
        if (!is_array($images)) {
            $images = [];
        }
        $poster = $this->getAttribute('poster');
        if (!is_string($poster) || $poster === '') {
            $poster = $images[0] ?? null;
        }

        // Время: start_at/end_at — это datetime из start_time/end_time.
        // Если времени нет, start_at/end_at остаются null, а start_date приходит отдельно.
        $startAt = $this->start_time?->toIso8601String();
        $endAt   = $this->end_time?->toIso8601String();

        // Источники
        $sources = [];
        $botUrl = null;

        if ($this->relationLoaded('eventSources') && $this->eventSources) {
            foreach ($this->eventSources as $s) {
                if (!$botUrl && !empty($s->generated_link)) {
                    $botUrl = $s->generated_link;
                }

                $sources[] = [
                    'id' => $s->id,
                    'source' => $s->source,
                    'post_external_id' => $s->post_external_id,
                    'external_url' => $s->external_url,
                    'generated_link' => $s->generated_link,
                    'published_at' => $s->published_at?->toIso8601String(),
                    'images' => is_array($s->images) ? $s->images : (json_decode((string) $s->images, true) ?: []),
                    'social_link_id' => $s->social_link_id,
                ];
            }
        }

        $priceMin = $this->price_min !== null ? (int) $this->price_min : null;

        return [
            // базовый контракт (карточка)
            'id' => (int) $this->id,
            'title' => $this->title,
            'city_slug' => $this->city_slug, // безопасно: берётся из select/join в web-репозитории
            'venue' => $this->venue ?? null, // если у тебя нет поля venue — будет null, это ок
            'address' => $this->address,
            'poster' => $poster,
            'images' => $images,

            // гео (реальные колонки events.latitude/events.longitude)
            'lat' => $this->latitude !== null ? (float) $this->latitude : null,
            'lng' => $this->longitude !== null ? (float) $this->longitude : null,

            // время
            'start_at' => $startAt,
            'end_at' => $endAt,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'time_precision' => $this->time_precision,
            'time_text' => $this->time_text,
            'timezone' => $this->timezone,

            // цена
            'price_status' => $this->price_status,
            'price_min' => $this->price_min !== null ? (int) $this->price_min : null,
            'price_max' => $this->price_max !== null ? (int) $this->price_max : null,
            'price_currency' => $this->price_currency,
            'price_text' => $this->price_text,
            'price_url' => $this->price_url,
            'free' => ($this->price_status === 'free') || ($priceMin === 0),

            // детальное для страницы
            'description' => $this->description,

            'original_text' => $this->relationLoaded('originalPost')
                ? ($this->originalPost?->text ?? null)
                : null,

            // ссылки
            'external_url' => $this->external_url,
            'bot_url' => $botUrl,

            // кто опубликовал
            'community' => $this->whenLoaded('community', function () {
                return [
                    'id' => (int) $this->community->id,
                    'name' => $this->community->name,
                    'city' => $this->community->city,
                    'avatar_url' => $this->community->avatar_url,
                ];
            }),

            // связанные записи/источники
            'sources' => $sources,
        ];
    }
}
