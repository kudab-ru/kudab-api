<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Sources;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация POST scan раздела Я.Афиши (admin, суперадмин). Тот же SSRF-формат
 * slug'а, что и при сохранении конфига (slug идёт в URL афиши при headless-fetch).
 */
class ScanYandexAfishaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('superadmin');
    }

    public function rules(): array
    {
        return [
            'city_slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'section' => ['required', 'string', 'regex:'.UpdateYandexAfishaConfigRequest::SLUG_REGEX],
        ];
    }

    public function messages(): array
    {
        return [
            'section.regex' => 'Slug раздела: латиница в нижнем регистре, цифры и дефис (без слешей/точек/пробелов).',
        ];
    }
}
