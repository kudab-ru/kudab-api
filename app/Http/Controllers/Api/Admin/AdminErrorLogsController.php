<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\ErrorLogs\IndexRequest;
use App\Models\ErrorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Просмотрщик структурированного лога ошибок (error_logs) для админки —
 * «где и какие ошибки» с drill-down и пометкой «решено» (фиксить на ходу).
 * Read + mark-resolved; гейтится route-группой admin|superadmin.
 */
class AdminErrorLogsController extends Controller
{
    public function index(IndexRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = (int) ($data['page'] ?? 1);
        $perPage = max(1, min((int) ($data['per_page'] ?? 50), 100));
        $includeResolved = (bool) ($data['include_resolved'] ?? false);

        $query = ErrorLog::query()
            ->with('community:id,name')
            ->orderByDesc('logged_at')
            ->orderByDesc('id');

        if (! $includeResolved) {
            $query->where('resolved', false);
        }
        if (! empty($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (! empty($data['job'])) {
            $query->where('job', $data['job']);
        }
        if (! empty($data['community_id'])) {
            $query->where('community_id', (int) $data['community_id']);
        }
        if (! empty($data['q'])) {
            $query->where('error_text', 'ilike', '%'.$data['q'].'%');
        }
        if (! empty($data['days'])) {
            $query->where('logged_at', '>=', now()->subDays((int) $data['days']));
        }

        $paginated = $query->paginate(perPage: $perPage, page: $page);

        return response()->json([
            'data' => array_map(fn (ErrorLog $e) => $this->serialize($e), $paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $resolved = $request->boolean('resolved', true);

        $log = ErrorLog::query()->find($id);
        if (! $log) {
            return response()->json(['message' => 'Запись ошибки не найдена'], 404);
        }

        $log->resolved = $resolved;
        $log->save();

        Log::info('admin:error-log:resolve', [
            'actor_id' => $request->user()?->id,
            'error_log_id' => $id,
            'resolved' => $resolved,
        ]);

        return response()->json(['data' => $this->serialize($log->fresh()->load('community:id,name'))]);
    }

    /**
     * Сводка по нерешённым: всего + разбивка по типам (для шапки страницы).
     *
     * @return array{total_unresolved: int, by_type: array<string,int>}
     */
    private function summary(): array
    {
        $byType = ErrorLog::query()
            ->where('resolved', false)
            ->select('type', DB::raw('count(*) as c'))
            ->groupBy('type')
            ->pluck('c', 'type')
            ->map(fn ($c) => (int) $c)
            ->all();

        return [
            'total_unresolved' => array_sum($byType),
            'by_type' => $byType,
        ];
    }

    private function serialize(ErrorLog $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'job' => $e->job,
            'community_id' => $e->community_id,
            'community_name' => $e->community?->name,
            'community_social_link_id' => $e->community_social_link_id,
            'error_text' => $e->error_text,
            'error_code' => $e->error_code,
            'resolved' => (bool) $e->resolved,
            'logged_at' => optional($e->logged_at)->toIso8601String(),
        ];
    }
}
