<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Kulisy;

use App\Services\Analytics\AnalyticsUnavailable;
use App\Services\Analytics\MetrikaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * Цифры для города на странице входа: сколько людей вчера открыли kudab и сколько ушли за билетом.
 * Метрика отвечает долго и иногда падает, поэтому ответ живёт в кеше; null — цифр нет, город без них.
 */
class KulisyCityController extends Controller
{
    private const TICKET_GOAL = 574656169;

    public function __invoke(MetrikaClient $metrika): JsonResponse
    {
        $day = now('Europe/Moscow')->subDay()->toDateString();

        $key = 'kulisy:city:'.$day;
        $data = Cache::get($key);
        if ($data === null) {
            try {
                $totals = $metrika->visits([
                    'date1' => $day,
                    'date2' => $day,
                    'metrics' => 'ym:s:users,ym:s:goal'.self::TICKET_GOAL.'users',
                ])['totals'] ?? [];
                $data = ['users' => isset($totals[0]) ? (int) $totals[0] : null, 'tickets' => isset($totals[1]) ? (int) $totals[1] : null];
                Cache::put($key, $data, now()->addHours(3));
            } catch (AnalyticsUnavailable) {
                $data = ['users' => null, 'tickets' => null];
            }
        }

        return response()->json(['data' => $data + ['date' => $day]]);
    }
}
