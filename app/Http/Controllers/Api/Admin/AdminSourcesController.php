<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\SourceOverview;
use Illuminate\Http\JsonResponse;

/**
 * Единый список источников для страницы «Источники» (суперадмин).
 *
 * Только чтение. Управление осталось там, где жило: тумблер и настройки
 * сайта — в AdminSourceProfilesController, разделы Я.Афиши — в
 * AdminYandexAfishaController, сообщества ВК и телеграма — на своей странице.
 * Здесь собирается ответ на три вопроса владельца: работает ли, сколько
 * приносит, когда собирал.
 */
class AdminSourcesController extends Controller
{
    public function index(SourceOverview $overview): JsonResponse
    {
        $rows = $overview->rows();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'cards_ahead_total' => array_sum(array_column($rows, 'cards_ahead')),
                'working' => count(array_filter($rows, fn ($r) => $r['state'] === 'ok')),
                'needs_attention' => count(array_filter(
                    $rows,
                    fn ($r) => in_array($r['state'], ['quiet', 'empty'], true),
                )),
            ],
        ]);
    }
}
