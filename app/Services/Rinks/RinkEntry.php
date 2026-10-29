<?php

declare(strict_types=1);

namespace App\Services\Rinks;

use App\Models\Rink;

/** Сеанс «Массового катания» из кассы и каток его площадки: цена входа и ссылка на все сеансы катка. */
final class RinkEntry
{
    /**
     * Касса пишет цену «от» по самому дешёвому билету, а это прокат коньков: вход берём из цен катка.
     *
     * @return array{name: string, url: string, entry: ?int, rental: ?int, sharp: ?int}|null
     */
    public static function forEvent(object $event): ?array
    {
        $venueId = $event->venue_id ?? null;
        if ($venueId === null || preg_match(RinkPage::SESSION_TITLE, (string) ($event->title ?? '')) !== 1) {
            return null;
        }

        return self::byVenue()[(int) $venueId] ?? null;
    }

    /** @return array<int, array{name: string, url: string, entry: ?int, rental: ?int, sharp: ?int}> */
    private static function byVenue(): array
    {
        return once(fn () => Rink::query()->with('venue:id,slug')->whereNotNull('venue_id')->where('hidden', false)->get()
            ->filter(fn (Rink $r) => $r->venue !== null)
            ->mapWithKeys(fn (Rink $r) => [(int) $r->venue_id => [
                'name' => (string) $r->name,
                'url' => '/venues/'.$r->venue->id.'-'.$r->venue->slug.'#katok',
                'entry' => isset($r->prices['entry']) ? (int) $r->prices['entry'] : null,
                'rental' => isset($r->prices['rental']) ? (int) $r->prices['rental'] : null,
                'sharp' => isset($r->prices['sharp']) ? (int) $r->prices['sharp'] : null,
            ]])->all());
    }
}
