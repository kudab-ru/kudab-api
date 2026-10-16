<?php

declare(strict_types=1);

namespace App\Services\Telegram\Scoring;

use Illuminate\Support\Facades\DB;

/**
 * Признаки, по которым оценка решает, интересно ли событие: вид, сколько у серии дат, продаёт ли
 * его касса, сколько у него оценок на Яндекс Афише, есть ли афиша, что за площадка.
 */
final class EventInterestSignals
{
    /** Билетные кассы среди источников: их событие — концерт или спектакль с продажей билетов. */
    public const TICKET_SOURCES = ['yandex_afisha', 'qtickets'];

    /** Даты серии считаются вперёд на этот срок. */
    public const REPEATS_DAYS = 30;

    /**
     * @param  list<int>  $eventIds
     * @return array<int, array{slug: string|null, parent: string|null, repeats: int, ticketed: bool, rating: int, photo: bool, venue_kind: string|null}>
     */
    public function forEvents(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        if ($eventIds === []) {
            return [];
        }

        $out = [];
        $groups = [];
        foreach (DB::table('events as e')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->whereIn('e.id', $eventIds)
            ->get(['e.id', 'e.event_group_id', 'v.kind as venue_kind']) as $row) {
            $out[(int) $row->id] = [
                'slug' => null,
                'parent' => null,
                'repeats' => 1,
                'ticketed' => false,
                'rating' => 0,
                'photo' => false,
                'venue_kind' => $row->venue_kind !== null ? (string) $row->venue_kind : null,
            ];
            if ($row->event_group_id !== null) {
                $groups[(int) $row->id] = (int) $row->event_group_id;
            }
        }

        // первичная тема с меньшим id, как во всей рассылке
        foreach (DB::table('event_interest as ei')
            ->join('interests as i', 'i.id', '=', 'ei.interest_id')
            ->leftJoin('interests as p', 'p.id', '=', 'i.parent_id')
            ->whereIn('ei.event_id', $eventIds)
            ->where('ei.rank', 0)
            ->orderBy('i.id')
            ->get(['ei.event_id', 'i.slug', 'p.slug as parent']) as $row) {
            $id = (int) $row->event_id;
            if (isset($out[$id]) && $out[$id]['slug'] === null) {
                $out[$id]['slug'] = (string) $row->slug;
                $out[$id]['parent'] = $row->parent !== null ? (string) $row->parent : null;
            }
        }

        if ($groups !== []) {
            $dates = DB::table('events')
                ->whereIn('event_group_id', array_values(array_unique($groups)))
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereBetween('start_time', [now()->utc(), now()->addDays(self::REPEATS_DAYS)->utc()])
                ->groupBy('event_group_id')
                ->selectRaw('event_group_id, count(*) as n')
                ->pluck('n', 'event_group_id');
            foreach ($groups as $id => $group) {
                $out[$id]['repeats'] = max(1, (int) ($dates[$group] ?? 1));
            }
        }

        $sources = implode(', ', array_fill(0, count(self::TICKET_SOURCES), '?'));
        foreach (DB::table('event_sources as es')
            ->leftJoin('context_posts as cp', 'cp.id', '=', 'es.context_post_id')
            ->whereIn('es.event_id', $eventIds)
            ->groupBy('es.event_id')
            ->selectRaw('es.event_id')
            ->selectRaw("bool_or(es.source in ({$sources})) as ticketed", self::TICKET_SOURCES)
            // число оценок лежит в разметке страницы Яндекс Афиши; не число — не оценка
            ->selectRaw("max(case when cp.structured_meta->'json_ld'->'aggregateRating'->>'ratingCount' ~ '^[0-9]+$'
                then (cp.structured_meta->'json_ld'->'aggregateRating'->>'ratingCount')::int end) as rating")
            ->selectRaw("bool_or(json_typeof(es.images) = 'array' and json_array_length(es.images) > 0) as photo")
            ->get() as $row) {
            $id = (int) $row->event_id;
            if (! isset($out[$id])) {
                continue;
            }
            $out[$id]['ticketed'] = (bool) $row->ticketed;
            $out[$id]['rating'] = (int) ($row->rating ?? 0);
            $out[$id]['photo'] = (bool) $row->photo;
        }

        return $out;
    }
}
