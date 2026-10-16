<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Журнал поиска по афише на сайте: что ищут и сколько находят. Метрика видит только длину
 * запроса, а повторный поиск на той же странице не видит вовсе.
 */
final class SearchLog
{
    public static function record(Request $request, string $q, ?int $cityId, int $found, array $filters): void
    {
        $agent = (string) $request->userAgent();
        if (! HumanAgent::looksLikePerson($agent)) {
            return;
        }

        // соль меняется каждый день: запросы одного человека склеиваются за сутки, но не дольше
        $visitor = substr(hash('sha256', implode('|', [
            $request->ip(), $agent, now('Europe/Moscow')->toDateString(), config('app.key'),
        ])), 0, 16);

        try {
            DB::table('search_queries')->insert([
                'q' => mb_substr($q, 0, 255),
                'city_id' => $cityId,
                'found' => $found,
                'filters' => $filters === [] ? null : json_encode($filters, JSON_UNESCAPED_UNICODE),
                'visitor' => $visitor,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // журнал не должен ронять сам поиск
            report($e);
        }
    }
}
