<?php

namespace App\Http\Requests\Admin\Communities;

use Illuminate\Foundation\Http\FormRequest;

class AdminCommunitiesImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // авторизацию реально решает middleware role/admin + sanctum,
        // поэтому тут можно true
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
            'auto_verify' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // чтобы "auto_verify": "false" тоже превращалось в bool
        if ($this->has('auto_verify')) {
            $this->merge([
                'auto_verify' => filter_var($this->input('auto_verify'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->input('auto_verify'),
            ]);
        }
    }
}
