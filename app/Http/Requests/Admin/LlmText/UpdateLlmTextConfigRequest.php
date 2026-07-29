<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\LlmText;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация выбора модели текст-движка (admin, суперадмин).
 *
 * authorize() = defense-in-depth поверх route-middleware role:superadmin.
 *
 * Значение `default` означает «вернуться к дефолту парсера» — так снимают ручной
 * выбор, не заводя отдельную кнопку сброса.
 */
class UpdateLlmTextConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('superadmin');
    }

    public function rules(): array
    {
        $allowed = array_merge(array_keys((array) config('llm_text_models', [])), ['default']);

        return [
            'bulk' => ['sometimes', 'nullable', 'string', Rule::in($allowed)],
            'tg' => ['sometimes', 'nullable', 'string', Rule::in($allowed)],
        ];
    }

    public function messages(): array
    {
        return [
            'bulk.in' => 'Неизвестная модель для массовых описаний.',
            'tg.in' => 'Неизвестная модель для ТГ-текстов.',
        ];
    }
}
