<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\ErrorLogs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Фильтры списка error_logs для админ-просмотрщика. authorize=true —
 * доступ гейтит route-группа (auth:sanctum + role:admin|superadmin).
 */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'max:64'],
            'job' => ['nullable', 'string', 'max:128'],
            // по умолчанию показываем только нерешённые; true → все (вкл. resolved).
            'include_resolved' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:200'],
            'community_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
