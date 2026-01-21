<?php

namespace App\Http\Requests\Admin\Communities;

use Illuminate\Foundation\Http\FormRequest;

class AdminCommunitiesStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'source' => ['nullable','string','max:64'],
            'avatar_url' => ['nullable','string','max:2048'],
            'external_id' => ['nullable','string','max:255'],
            'city_id' => ['nullable','integer','min:1'],
        ];
    }
}
