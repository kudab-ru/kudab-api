<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebEventResource;
use App\Models\City;
use App\Models\Event;
use App\Repositories\EventRepository;
use App\Services\Occasions\DayPage;
use App\Services\Occasions\WeekendPage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Главная сайта: стена — «Главное» сегодня и завтра, ряд «Куда на выходных» — «Главное» выходных.
 * Отбор тот же, что у страниц «Сегодня» и «На выходных», иначе главная повторяет ленту по времени начала.
 */
final class HomeController extends Controller
{
    /** столько постеров в стене на компьютере, телефон показывает восемь */
    private const WALL = 12;

    public function __construct(
        private readonly DayPage $days,
        private readonly WeekendPage $weekends,
        private readonly EventRepository $repo,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $slug = (string) $request->query('city', 'voronezh');
        abort_unless((bool) preg_match('/^[a-z0-9-]{1,64}$/', $slug), 404);
        $city = City::query()->where('slug', $slug)->where('status', 'active')->first();
        abort_if($city === null, 404);

        $now = Carbon::now('Europe/Moscow');
        $weekend = array_map(fn (array $p) => (int) $p['id'],
            array_values(array_filter($this->weekends->picks($city, $now), fn (array $p) => ! $p['is_past'])));

        return response()->json(['data' => [
            'wall' => WebEventResource::collection($this->wall($city, $now)),
            'weekend' => WebEventResource::collection($this->repo->webCardsByIds($weekend)),
        ]]);
    }

    /**
     * Главное сегодня, за ним главное завтра. Не набралось — ближайшее с картинкой из программы этих дней,
     * чтобы стена не пустела поздно вечером.
     */
    private function wall(City $city, Carbon $now): EloquentCollection
    {
        $picks = [];
        $spare = [];
        foreach ([0, 1] as $offset) {
            $day = $this->days->data($city, $now, $offset);
            foreach ($day['picks'] as $p) {
                if (! $p['is_past']) {
                    $picks[(int) $p['id']] = true;
                }
            }
            foreach ($day['days'] as $d) {
                foreach ([...$d['events'], ...($d['rest'] ?? [])] as $r) {
                    if (! $r['is_past'] && ! isset($picks[(int) $r['id']])) {
                        $spare[(int) $r['id']] = true;
                    }
                }
            }
        }
        // у строк программы картинок нет, поэтому запасные проверяются на картинку уже карточками
        $cards = $this->repo->webCardsByIds([...array_keys($picks), ...array_slice(array_keys($spare), 0, 40)]);

        return $cards->filter(fn (Event $e) => isset($picks[(int) $e->id]) || $e->poster() !== null)
            ->take(self::WALL)
            ->values();
    }
}
