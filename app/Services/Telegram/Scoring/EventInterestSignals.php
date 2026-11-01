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

    /** Те же кассы, но их события приходят источником `site`, вперемешку с сайтами самих площадок. */
    public const TICKET_SITES = ['kassir.ru', 'live.mts.ru', 'quicktickets.ru', 'bezantrakta.ru', 'afisha.ru'];

    /** Театр, филармония, концертный зал: у платного вечера там своя касса, даже без сайта-кассы среди источников. */
    public const STAGE_VENUE_KIND = 'Сцены';

    /** Даты серии считаются вперёд на этот срок. */
    public const REPEATS_DAYS = 30;

    /**
     * @param  list<int>  $eventIds
     * @return array<int, array{slug: string|null, parent: string|null, repeats: int, past: int, run_until: string|null, ticketed: bool, rating: int, photo: bool, venue_kind: string|null}>
     */
    public function forEvents(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        if ($eventIds === []) {
            return [];
        }

        $out = [];
        $groups = [];
        $loose = [];
        foreach (DB::table('events as e')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->whereIn('e.id', $eventIds)
            ->get(['e.id', 'e.event_group_id', 'e.venue_id', 'e.price_min', 'v.kind as venue_kind']) as $row) {
            $venueKind = $row->venue_kind !== null ? (string) $row->venue_kind : null;
            $out[(int) $row->id] = [
                'slug' => null,
                'parent' => null,
                'repeats' => 1,
                'past' => 0,
                'run_until' => null,
                'ticketed' => $venueKind === self::STAGE_VENUE_KIND && (int) ($row->price_min ?? 0) > 0,
                'rating' => 0,
                'photo' => false,
                'venue_kind' => $venueKind,
            ];
            if ($row->event_group_id !== null) {
                $groups[(int) $row->id] = (int) $row->event_group_id;
            }
            if ($row->venue_id !== null) {
                $loose[] = (int) $row->id;
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

        $now = now()->utc();
        if ($groups !== []) {
            $today = now()->setTimezone('Europe/Moscow')->startOfDay();
            $dates = DB::table('events')
                ->whereIn('event_group_id', array_values(array_unique($groups)))
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereBetween('start_time', [now()->subDays(self::REPEATS_DAYS)->utc(), now()->addDays(self::REPEATS_DAYS)->utc()])
                    ->orWhere(fn ($d) => $d->whereNull('start_time')->where('time_precision', 'date')
                        ->whereBetween('start_date', [$today->toDateString(), $today->copy()->addDays(self::REPEATS_DAYS)->toDateString()])))
                ->groupBy('event_group_id')
                ->selectRaw('event_group_id')
                ->selectRaw('count(*) filter (where start_time is null or start_time >= ?) as n', [$now])
                ->selectRaw('count(*) filter (where start_time < ?) as past', [$now])
                ->selectRaw('count(*) filter (where start_time is null) as days, max(start_date) filter (where start_time is null) as last_day')
                ->get()
                ->keyBy('event_group_id');
            foreach ($groups as $id => $group) {
                $row = $dates[$group] ?? null;
                $out[$id]['repeats'] = max(1, (int) ($row->n ?? 1));
                $out[$id]['past'] = (int) ($row->past ?? 0);
                // выставка, заведённая днём на каждую дату, — прокат до своего последнего дня
                if ($row !== null && (int) $row->days > 1) {
                    $out[$id]['run_until'] = substr((string) $row->last_day, 0, 10);
                }
            }
        }

        // еженедельный стендап парсер то заводит отдельными событиями, то делит на две группы:
        // серию считаем и по названию на той же площадке и берём большее из двух
        if ($loose !== []) {
            foreach (DB::table('events as e')
                ->join('events as o', fn ($j) => $j->on('o.venue_id', '=', 'e.venue_id')->whereRaw('lower(o.title) = lower(e.title)'))
                ->whereIn('e.id', $loose)
                ->where('o.status', 'active')
                ->whereNull('o.deleted_at')
                ->whereBetween('o.start_time', [now()->subDays(self::REPEATS_DAYS)->utc(), now()->addDays(self::REPEATS_DAYS)->utc()])
                ->groupBy('e.id')
                ->selectRaw('e.id')
                ->selectRaw('count(*) filter (where o.start_time >= ?) as n', [$now])
                ->selectRaw('count(*) filter (where o.start_time < ?) as past', [$now])
                ->get() as $row) {
                $id = (int) $row->id;
                $out[$id]['repeats'] = max($out[$id]['repeats'], (int) $row->n);
                $out[$id]['past'] = max($out[$id]['past'], (int) $row->past);
            }
        }

        $sources = implode(', ', array_fill(0, count(self::TICKET_SOURCES), '?'));
        $sites = '(^|\\.)('.implode('|', array_map(fn ($h) => preg_quote($h, null), self::TICKET_SITES)).')$';
        foreach (DB::table('event_sources as es')
            ->leftJoin('context_posts as cp', 'cp.id', '=', 'es.context_post_id')
            ->whereIn('es.event_id', $eventIds)
            ->groupBy('es.event_id')
            ->selectRaw('es.event_id')
            ->selectRaw("bool_or(es.source in ({$sources}) or (es.source = 'site'
                and substring(coalesce(es.external_url, es.generated_link) from '^https?://([^/:]+)') ~ ?)) as ticketed",
                [...self::TICKET_SOURCES, $sites])
            // число оценок лежит в разметке страницы Яндекс Афиши; не число — не оценка
            ->selectRaw("max(case when cp.structured_meta->'json_ld'->'aggregateRating'->>'ratingCount' ~ '^[0-9]+$'
                then (cp.structured_meta->'json_ld'->'aggregateRating'->>'ratingCount')::int end) as rating")
            ->selectRaw("bool_or(json_typeof(es.images) = 'array' and json_array_length(es.images) > 0) as photo")
            ->get() as $row) {
            $id = (int) $row->event_id;
            if (! isset($out[$id])) {
                continue;
            }
            $out[$id]['ticketed'] = $out[$id]['ticketed'] || (bool) $row->ticketed;
            $out[$id]['rating'] = (int) ($row->rating ?? 0);
            $out[$id]['photo'] = (bool) $row->photo;
        }

        return $out;
    }
}
