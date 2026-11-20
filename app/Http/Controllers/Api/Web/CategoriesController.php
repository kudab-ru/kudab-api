<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Occasions\CategoryPage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoriesController extends Controller
{
    public function __construct(private readonly CategoryPage $page) {}

    public function show(Request $request, string $page): JsonResponse
    {
        abort_unless($page === 'koncerty', 404);
        $slug = (string) $request->query('city', 'voronezh');
        abort_unless((bool) preg_match('/^[a-z0-9-]{1,64}$/', $slug), 404);
        $city = City::query()->where('slug', $slug)->where('status', 'active')->first();
        if ($city === null) {
            $city = new City;
            $city->id = 0;
            $city->slug = $slug;
            $city->name = $slug;
        }

        $want = (string) $request->query('w', '');
        $month = (string) $request->query('month', '');

        return response()->json(['data' => $this->page->show(
            $city,
            Carbon::now('Europe/Moscow'),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $want) ? $want : null,
            preg_match('/^\d{4}-\d{2}$/', $month) ? $month : null,
        )]);
    }
}
