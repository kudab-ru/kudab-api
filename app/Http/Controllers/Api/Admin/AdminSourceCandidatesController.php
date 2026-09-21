<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Очередь доменов-кандидатов в источники (source_candidates, суперадмин).
 *
 * Механика подключения сайтов готова целиком — probe, карантин, self-heal,
 * онбординг соседним контроллером. Не хватало первого шага воронки: домены
 * искала read-only команда вне расписания, и владелец нигде не видел, что
 * стоит подключить. Теперь ночной parser:sources:candidates --save пишет сюда,
 * а эта ручка показывает очередь.
 *
 * Api только читает и прячет. Наполняет таблицу парсер, подключение идёт
 * штатным онбордингом (probe-requests) — отдельной кнопки «подключить» тут
 * нет намеренно: профиль обязан родиться через probe, а не из строки очереди.
 */
class AdminSourceCandidatesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $withHidden = $request->boolean('hidden');

        $rows = DB::table('source_candidates')
            ->when(! $withHidden, fn ($q) => $q->whereNull('dismissed_at'))
            ->orderByDesc('communities')
            ->orderByDesc('posts')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'domain' => (string) $r->domain,
                'sample_url' => $r->sample_url,
                'communities' => (int) $r->communities,
                'posts' => (int) $r->posts,
                'verdict' => $r->verdict,
                'checked_at' => $r->checked_at,
                'first_seen_at' => $r->first_seen_at,
                'last_seen_at' => $r->last_seen_at,
                'dismissed' => $r->dismissed_at !== null,
            ])->all(),
            'meta' => [
                'hidden_count' => (int) DB::table('source_candidates')->whereNotNull('dismissed_at')->count(),
            ],
        ]);
    }

    public function dismiss(int $id): JsonResponse
    {
        return $this->setDismissed($id, now());
    }

    public function restore(int $id): JsonResponse
    {
        return $this->setDismissed($id, null);
    }

    private function setDismissed(int $id, mixed $value): JsonResponse
    {
        $affected = DB::table('source_candidates')->where('id', $id)->update([
            'dismissed_at' => $value,
            'updated_at' => now(),
        ]);

        if ($affected === 0) {
            return response()->json(['message' => 'Кандидат не найден'], 404);
        }

        return response()->json(['ok' => true]);
    }
}
