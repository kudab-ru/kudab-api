<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Заявки организаторов в админке (суперадмин).
 *
 * Ручка приёма публичная, поэтому сюда попадает и мусор: разбирать заявки
 * должен человек. Разобранные не удаляем, а помечаем — по ним видно, сколько
 * настоящих обращений приходит и стоит ли звать организаторов активнее.
 */
class AdminOrganizerLeadsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $withResolved = $request->boolean('resolved');

        $rows = DB::table('organizer_leads')
            ->when(! $withResolved, fn ($q) => $q->whereNull('resolved_at'))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'kind' => (string) $r->kind,
                'source_url' => $r->source_url,
                'contact' => (string) $r->contact,
                'city' => $r->city,
                'comment' => $r->comment,
                'page_path' => $r->page_path,
                'created_at' => $r->created_at,
                'resolved_at' => $r->resolved_at,
                'resolution' => $r->resolution,
            ])->all(),
            'meta' => [
                'new_count' => (int) DB::table('organizer_leads')->whereNull('resolved_at')->count(),
            ],
        ]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['connected', 'declined', 'spam'])],
        ]);

        $affected = DB::table('organizer_leads')->where('id', $id)->update([
            'resolved_at' => now(),
            'resolution' => $data['resolution'],
            'updated_at' => now(),
        ]);

        if ($affected === 0) {
            return response()->json(['message' => 'Заявка не найдена'], 404);
        }

        return response()->json(['ok' => true]);
    }
}
