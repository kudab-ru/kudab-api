<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Proxy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация PUT желаемого состояния VPN-прокси (admin, суперадмин, 2b).
 *
 * authorize() = defense-in-depth поверх route-middleware role:superadmin.
 *
 * subscription_url — секрет: принимаем только https-URL подписки, ограничиваем
 * длину, храним шифрованным (cast в модели), в ответах не возвращаем. Пустая
 * строка/null = очистить подписку (выключит применение до новой).
 */
class UpdateProxyConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('superadmin');
    }

    public function rules(): array
    {
        return [
            // https-only, до 2000 символов; null/'' — очистить
            'subscription_url' => ['sometimes', 'nullable', 'string', 'max:2000', 'starts_with:https://'],
            'mode' => ['sometimes', 'string', 'in:failover,single'],
            // индекс сервера подписки для mode=single (0..255)
            'selected_server_index' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'subscription_url.starts_with' => 'Ссылка подписки должна начинаться с https://',
        ];
    }
}
