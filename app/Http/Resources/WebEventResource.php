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

        // group: отдаём только если репозиторий положил group_dates/group_count
        $group = null;
        $gid = (int) ($this->event_group_id ?? 0);
        if ($gid > 0) {
            $dates = $this->getAttribute('group_dates');

            if (is_string($dates)) {
                $decoded = json_decode($dates, true);
                $dates = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($dates)) $dates = null;

            $count = $this->getAttribute('group_count');
            $count = is_numeric($count) ? (int) $count : ($dates ? count($dates) : 0);

            // В ленте показываем group только если реально есть “серия”
            if ($dates && $count >= 2) {
                $group = [
                    'id'    => $gid,
                    'count' => $count,
                    'dates' => $dates,
                ];
            }
        }

        // siblings: ось B (kudab-parser/TASKS.md 2.3). Заполняется
        // hydrateSiblings в репозитории при `grouped_by_post=1`. Включаем в
        // payload только если массив непустой — иначе ключ опускаем (фронт
        // ловит через Array.isArray && length>=1).
        $siblings = $this->getAttribute('siblings');
        $siblings = is_array($siblings) && count($siblings) > 0 ? $siblings : null;

        // interests: всегда array (если relation не загружен — пустой массив,
        // чтобы фронт не проверял null/undefined). Этап 2: parent_slug в payload
        // event'а не дублируем — фронт строит дерево из /api/web/interests.
        $interests = [];
        if ($this->relationLoaded('interests') && $this->interests) {
            foreach ($this->interests as $i) {
                if (!$i->slug) continue;
                $interests[] = [
                    'slug' => (string) $i->slug,
                    'name' => (string) $i->name,
                ];
            }
        }

        $out = [
            'id'             => (int) $this->id,
            'title'          => (string) ($this->title ?? ''),

            'city_slug'      => $this->getAttribute('city_slug') ?: null,
            'venue'          => $this->relationLoaded('venue') && $this->venue
                ? (new VenueBadgeResource($this->venue))->toArray($request)
                : null,
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
            'tickets_status' => (string) ($this->tickets_status ?? 'unknown'),
            'price_min'      => $this->price_min !== null ? (int) $this->price_min : null,
            'price_max'      => $this->price_max !== null ? (int) $this->price_max : null,
            'price_text'     => $this->price_text !== null ? (string) $this->price_text : null,
            'price_url'      => $this->price_url !== null ? (string) $this->price_url : null,

            'free'           => ($this->price_status === 'free')
                || ((int) ($this->price_min ?? -1) === 0 && $this->price_max === null),

            'is_past' => (bool) ($this->getAttribute('__is_past') ?? false),

            'group' => $group,

            'interests' => $interests,
        ];

        if ($siblings !== null) {
            $out['siblings'] = $siblings;
        }

        return $out;
    }
}
