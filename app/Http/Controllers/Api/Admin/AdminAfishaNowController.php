<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Analytics\AfishaNow;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/** «Что сейчас в афише»: отдаёт готовый снимок, который каждые полчаса пересчитывает `afisha:now`. */
class AdminAfishaNowController extends Controller
{
    public function show(AfishaNow $afisha): JsonResponse
    {
        return response()->json(['data' => $afisha->get()]);
    }

    /** Кнопка «Обновить»: счёт быстрый, поэтому пересчитываем сразу, без очереди. */
    public function refresh(AfishaNow $afisha): JsonResponse
    {
        return response()->json(['data' => $afisha->rebuild()]);
    }
}
