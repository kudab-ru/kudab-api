<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Приём заявок от организаторов (публично, без авторизации).
 *
 * Форма на сайте (widgets/organizer-lead) была написана и помечена «НЕ
 * ПОДКЛЮЧЁН»: этой ручки не существовало, и страница /organizers звала
 * присылать ссылки в пустоту.
 *
 * Зачем вообще: до сих пор источники искали только мы сами — мониторили
 * ссылки в постах, разведывали сайты, подписывались на паблики. Этот путь
 * структурно слеп к мелкому: маленькое сообщество ссылок на себя не оставляет.
 * А организатору квартирника наш анонс нужнее, чем нам его квартирник, —
 * значит, он придёт сам, если дать куда.
 *
 * Ручка публичная, поэтому исходит из того, что придёт мусор: лимит по
 * частоте на маршруте, жёсткие ограничения длин, ip и user-agent в записи для
 * разбора злоупотреблений. Ответ всегда одинаковый и ничего не подтверждает —
 * по нему нельзя понять, приняли запись или отбросили.
 */
class OrganizerLeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['sometimes', 'nullable', Rule::in(['source', 'sponsor'])],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url'],
            'contact' => ['required', 'string', 'min:3', 'max:300'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'page_path' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $kind = (string) ($data['kind'] ?? 'source');

        // «Подключите мой источник» без ссылки разобрать нельзя: контакт есть,
        // а что подключать — неизвестно. Для спонсорства ссылка не нужна.
        if ($kind === 'source' && trim((string) ($data['source_url'] ?? '')) === '') {
            return response()->json([
                'message' => 'Укажите ссылку на сообщество или сайт с афишей.',
                'errors' => ['source_url' => ['Нужна ссылка на источник.']],
            ], 422);
        }

        $id = DB::table('organizer_leads')->insertGetId([
            'kind' => $kind,
            'source_url' => $data['source_url'] ?? null,
            'contact' => trim((string) $data['contact']),
            'city' => $data['city'] ?? null,
            'comment' => $data['comment'] ?? null,
            'page_path' => $data['page_path'] ?? null,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('organizer-lead:received', [
            'id' => $id,
            'kind' => $kind,
            'source_url' => $data['source_url'] ?? null,
        ]);

        return response()->json(['ok' => true], 201);
    }
}
