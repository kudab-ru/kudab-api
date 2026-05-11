<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\ParsingStatus\IndexRequest;
use App\Models\ParsingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Мониторинг и управление заморозкой источников парсинга.
 *
 * Аналог `php artisan parser:status:report` + `make parsing-errors`,
 * только через HTTP — для UI админки.
 */
class AdminParsingStatusController extends Controller
{
    public function index(IndexRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page    = (int) ($data['page'] ?? 1);
        $perPage = max(1, min((int) ($data['per_page'] ?? 50), 100));

        $query = ParsingStatus::query()
            ->with(['socialLink:id,community_id,social_network_id,url',
                    'socialLink.community:id,name,city_id',
                    'socialLink.community.city:id,name,slug',
                    'socialLink.socialNetwork:id,name'])
            ->orderByDesc('updated_at');

        // По дефолту показываем только замороженные — это типичный кейс
        $frozenFilter = $data['frozen'] ?? true;
        if ($frozenFilter !== null) {
            $query->where('is_frozen', (bool) $frozenFilter);
        }

        if (!empty($data['reason'])) {
            $query->where('frozen_reason', $data['reason']);
        }

        $paginated = $query->paginate(perPage: $perPage, page: $page);

        return response()->json([
            'data' => array_map(
                fn (ParsingStatus $ps) => $this->serialize($ps),
                $paginated->items(),
            ),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function unfreeze(Request $request, int $linkId): JsonResponse
    {
        $ps = ParsingStatus::where('community_social_link_id', $linkId)->first();
        if (!$ps) {
            return response()->json(['message' => 'Запись ParsingStatus не найдена для этой ссылки'], 404);
        }

        $wasFrozen = (bool) $ps->is_frozen;

        $ps->is_frozen     = false;
        $ps->unfreeze_at   = null;
        $ps->retry_count   = 0;
        $ps->frozen_reason = null;
        $ps->save();

        Log::info('admin:parsing-status-unfreeze', [
            'link_id'    => $linkId,
            'was_frozen' => $wasFrozen,
            'actor_id'   => $request->user()?->id,
        ]);

        // Перезагружаем с тем же набором relations, что и в index(), чтобы
        // serialize() мог построить полный nested ответ
        $fresh = ParsingStatus::with([
            'socialLink:id,community_id,social_network_id,url,status',
            'socialLink.community:id,name,city_id',
            'socialLink.community.city:id,name,slug',
            'socialLink.socialNetwork:id,name',
        ])->find($ps->id);

        return response()->json([
            'data' => $this->serialize($fresh),
        ]);
    }

    private function serialize(ParsingStatus $ps): array
    {
        $link = $ps->socialLink;

        return [
            'id'                       => (int) $ps->id,
            'community_social_link_id' => (int) $ps->community_social_link_id,
            'is_frozen'                => (bool) $ps->is_frozen,
            'frozen_reason'            => $ps->frozen_reason,
            'unfreeze_at'              => optional($ps->unfreeze_at)->toIso8601String(),
            'last_error_code'          => $ps->last_error_code,
            'last_error'               => $ps->last_error
                ? mb_substr((string) $ps->last_error, 0, 500)
                : null,
            'last_success_at'          => optional($ps->last_success_at)->toIso8601String(),
            'total_failures'           => (int) $ps->total_failures,
            'retry_count'              => (int) $ps->retry_count,
            'updated_at'               => optional($ps->updated_at)->toIso8601String(),
            'link' => $link ? [
                'id'             => (int) $link->id,
                'url'            => (string) ($link->url ?? ''),
                'status'         => (string) ($link->status ?? 'active'),
                'social_network' => $link->socialNetwork ? [
                    'id'   => (int) $link->socialNetwork->id,
                    'name' => (string) $link->socialNetwork->name,
                ] : null,
                'community' => $link->community ? [
                    'id'   => (int) $link->community->id,
                    'name' => (string) $link->community->name,
                    'city' => $link->community->city ? [
                        'id'   => (int) $link->community->city->id,
                        'name' => (string) $link->community->city->name,
                        'slug' => (string) $link->community->city->slug,
                    ] : null,
                ] : null,
            ] : null,
        ];
    }
}
