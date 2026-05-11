<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\CommunityLinks\UpdateStatusRequest;
use App\Models\CommunitySocialLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Управление статусами CommunitySocialLink (ban / gray / unban).
 *
 * Аналог make-команд `link-ban / link-unban / link-gray` парсера, но через
 * HTTP. Меняем атомарно `community_social_links.status` enum
 * (active|gray|black). Парсер на следующей итерации сбора уважит новый
 * статус (active — собираем, gray — пропускаем но не удаляем, black —
 * чёрный список).
 */
class AdminCommunityLinksController extends Controller
{
    public function updateStatus(UpdateStatusRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        $link = CommunitySocialLink::findOrFail($id);
        $prevStatus = $link->status;

        if ($prevStatus === $data['status']) {
            // Идемпотентно: статус совпадает — ничего не меняем, не шумим
            return response()->json([
                'data' => $this->serialize($link, $prevStatus),
            ]);
        }

        DB::transaction(function () use ($link, $data) {
            $link->status = $data['status'];
            $link->save();
        });

        Log::info('admin:community-link-status', [
            'link_id'   => $link->id,
            'prev'      => $prevStatus,
            'next'      => $link->status,
            'reason'    => $data['reason'] ?? null,
            'actor_id'  => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $this->serialize($link->fresh(), $prevStatus),
        ]);
    }

    private function serialize(CommunitySocialLink $link, string $prevStatus): array
    {
        return [
            'id'              => (int) $link->id,
            'community_id'    => (int) $link->community_id,
            'social_network_id' => (int) $link->social_network_id,
            'url'             => (string) ($link->url ?? ''),
            'status'          => (string) $link->status,
            'previous_status' => $prevStatus,
        ];
    }
}
