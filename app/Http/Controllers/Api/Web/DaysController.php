<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Occasions\DayPage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Страница «Сегодня» сайта: /afisha/{город}/segodnya. */
final class DaysController extends Controller
{
    public function __construct(private readonly DayPage $page) {}

    /** Вкладки «сегодня» и «завтра» и выбранный день целиком: `d` — его дата. */
    public function show(Request $request): JsonResponse
    {
        $slug = (string) $request->query('city', 'voronezh');
        abort_unless((bool) preg_match('/^[a-z0-9-]{1,64}$/', $slug), 404);
        $city = City::query()->where('slug', $slug)->where('status', 'active')->first();
        abort_if($city === null, 404);

        $want = (string) $request->query('d', '');

        return response()->json(['data' => $this->page->show(
            $city,
            Carbon::now('Europe/Moscow'),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $want) ? $want : null,
        )]);
    }
}
