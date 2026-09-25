<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * Ближайшие выходные — одна формула на ленту и на подборку.
 *
 * Жила приватной в контроллере ленты. Подборке понадобилась та же: рубрика «На
 * выходных» считает по ней окно отбора, а подвал ведёт на `?when=weekend`,
 * который лента разворачивает этой же функцией. Разойдись они на один день —
 * и пост пообещал бы одно число, а страница показала другое; ровно от этого
 * уходит весь счёт в подборке.
 *
 * Правило: в субботу и воскресенье «выходные» — это ТЕКУЩИЕ, а не следующие.
 * В субботу читатель ещё успевает на оба дня, в воскресенье — на один, и звать
 * его через неделю, когда всё идёт сегодня, было бы издевательством.
 */
final class WeekendWindow
{
    /**
     * @return array{0: Carbon, 1: Carbon} начало субботы и конец воскресенья
     */
    public static function for(Carbon $now): array
    {
        $dow = $now->dayOfWeek;

        if ($dow === Carbon::SATURDAY) {
            return [$now->copy()->startOfDay(), $now->copy()->addDay()->endOfDay()];
        }

        if ($dow === Carbon::SUNDAY) {
            return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
        }

        $from = $now->copy()->addDays((Carbon::SATURDAY - $dow + 7) % 7)->startOfDay();

        return [$from, $from->copy()->addDay()->endOfDay()];
    }
}
