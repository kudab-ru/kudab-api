<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Occasions\WeekendPage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Страница «На выходных» сайта: /afisha/{город}/na-vyhodnyh. */
final class WeekendsController extends Controller
{
    public function __construct(private readonly WeekendPage $page) {}

    /** Вкладки ближайших выходных и выбранные целиком: `w` — дата их субботы. */
    public function show(Request $request): JsonResponse
    {
        $slug = (string) $request->query('city', 'voronezh');
        abort_unless((bool) preg_match('/^[a-z0-9-]{1,64}$/', $slug), 404);
        $city = City::query()->where('slug', $slug)->where('status', 'active')->first();
        abort_if($city === null, 404);

        $want = (string) $request->query('w', '');

        return response()->json(['data' => $this->page->show(
            $city,
            Carbon::now('Europe/Moscow'),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $want) ? $want : null,
        )]);
    }
}
