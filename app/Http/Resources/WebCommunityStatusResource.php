<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Публичный ответ по одному сообществу для потока «добавь своё сообщество»
 * (`GET /web/communities/{id}`, виджет OrganizersCommunityStatus на фронте).
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ РЕСУРС. Раньше этот публичный маршрут вёл в
 * AdminCommunitiesController::show + Admin\CommunityResource, то есть
 * без авторизации наружу уходили:
 *   - verification_meta   — служебная выкладка модерации,
 *   - last_checked_at     — когда робот последний раз ходил в источник,
 *   - deleted_at          — вместе с withTrashed(), то есть удалённые записи
 *                           оставались публично доступными по id.
 * Тот же метод в админской группе закрыт auth:sanctum + role:admin|superadmin —
 * получалось, что один метод отдаётся и по паролю, и без него.
 *
 * ЧТО ОСТАВЛЕНО И ПОЧЕМУ. verification_status и is_verified остаются: на них
 * держится статус в виджете организатора («на проверке» / «подключено» /
 * «нужны данные»), без них поток ломается. Это осознанный размен: статус
 * своей заявки человек видеть должен, но сейчас его может запросить кто угодно
 * по любому id. Сделать статус по-настоящему приватным — значит завести
 * владение заявкой (токен или сессия), это отдельное решение.
 *
 * Форма ответа намеренно повторяет прежнюю (минус убранные поля), чтобы
 * правка безопасности ничего не меняла в интерфейсе.
 */
class WebCommunityStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $city = $this->relationLoaded('city') ? $this->getRelation('city') : null;

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'description' => $this->description,

            'city' => $city ? [
                'id' => $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ] : null,

            'city_id' => $this->city_id,

            'avatar_url' => $this->avatar_url,
            'image_url' => $this->image_url ?? null,

            'verification_status' => $this->verification_status,
            'is_verified' => (bool) ($this->is_verified ?? false),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
