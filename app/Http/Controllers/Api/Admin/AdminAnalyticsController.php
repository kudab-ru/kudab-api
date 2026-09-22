<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Console\Commands\AnalyticsWarmCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * Аналитика роста для админки.
 *
 * Ручка НИЧЕГО не считает. Сборка отчёта стоит восьми обращений к Метрике и
 * Вебмастеру и занимает около минуты — nginx рвёт такой запрос на шестидесятой
 * секунде, и страница получала бы пустой ответ вместо данных. Поэтому кэш
 * наполняет команда `analytics:warm`: по расписанию каждые три часа, а по
 * кнопке «Обновить» — тем же планировщиком на ближайшей минуте.
 */
class AdminAnalyticsController extends Controller
{
    /** Замок на ручное обновление: не чаще раза в десять минут. */
    private const REFRESH_LOCK = 'admin:analytics:growth:refresh-lock';

    public function growth(Request $request): JsonResponse
    {
        /** @var array{data: array<string, mixed>, meta: array<string, mixed>}|null $payload */
        $payload = Cache::get(AnalyticsWarmCommand::KEY);

        $rebuilding = false;
        $refused = false;

        if ($payload === null || $request->boolean('refresh')) {
            if (Cache::add(self::REFRESH_LOCK, 1, now()->addMinutes(10))) {
                // Не очередь, а флажок: его каждую минуту подбирает
                // планировщик (`analytics:warm --requested-only`).
                Cache::put(AnalyticsWarmCommand::REQUEST_FLAG, true, now()->addMinutes(30));
                $rebuilding = true;
            } else {
                $refused = true;
            }
        }

        if ($payload === null) {
            return response()->json($this->empty($rebuilding));
        }

        // stale — «обновления просили, а отдали прежнее»: замок ещё держит.
        $payload['meta']['stale'] = $refused;
        $payload['meta']['rebuilding'] = $rebuilding;

        return response()->json($payload);
    }

    /**
     * Первый заход после выката: кэша ещё нет. Отдаём честную пустоту —
     * страница скажет «считаю», а не соврёт нулями.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function empty(bool $rebuilding): array
    {
        return [
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'period_days' => 30,
                'verdict' => null,
                'search' => null,
                'chain' => null,
                'sources' => null,
                'landing' => null,
                'card_sources' => null,
                'goals' => null,
                'blind' => null,
            ],
            'meta' => [
                'cached_until' => now()->toIso8601String(),
                'stale' => false,
                'rebuilding' => $rebuilding,
                'errors' => [],
            ],
        ];
    }
}
