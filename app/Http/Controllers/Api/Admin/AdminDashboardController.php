<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\Community;
use App\Models\CommunitySocialLink;
use App\Models\Event;
use App\Models\ParsingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Сводка для главного экрана админки. Все цифры — снапшоты на момент
 * запроса. Кэширование не используем намеренно — данных мало,
 * SQL aggregates быстрые, дашборд открывает максимум 10 человек.
 */
class AdminDashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $now = now();
        $day = $now->copy()->subDay();
        $week = $now->copy()->subDays(7);

        $eventsTotal     = Event::query()->count();
        $events24h       = Event::query()->where('created_at', '>=', $day)->count();
        $events7d        = Event::query()->where('created_at', '>=', $week)->count();
        $eventsDeleted7d = Event::query()->onlyTrashed()->where('deleted_at', '>=', $week)->count();

        $communitiesTotal = Community::query()->count();
        $linksTotal       = CommunitySocialLink::query()->count();
        $linksByStatus    = CommunitySocialLink::query()
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $frozenSources = ParsingStatus::query()->where('is_frozen', true)->count();
        $frozenByReason = ParsingStatus::query()
            ->where('is_frozen', true)
            ->select('frozen_reason', DB::raw('count(*) as c'))
            ->groupBy('frozen_reason')
            ->pluck('c', 'frozen_reason')
            ->all();

        $failedJobs = DB::table('failed_jobs')->count();

        return response()->json([
            'data' => [
                'generated_at' => $now->toIso8601String(),
                'events' => [
                    'total'          => $eventsTotal,
                    'created_24h'    => $events24h,
                    'created_7d'     => $events7d,
                    'soft_deleted_7d' => $eventsDeleted7d,
                ],
                'communities' => [
                    'total'        => $communitiesTotal,
                    'links_total'  => $linksTotal,
                    'links_active' => (int) ($linksByStatus['active'] ?? 0),
                    'links_gray'   => (int) ($linksByStatus['gray'] ?? 0),
                    'links_black'  => (int) ($linksByStatus['black'] ?? 0),
                ],
                'parsing' => [
                    'frozen_sources'  => $frozenSources,
                    'frozen_by_reason' => $frozenByReason,
                    'failed_jobs'     => $failedJobs,
                ],
            ],
        ]);
    }
}
