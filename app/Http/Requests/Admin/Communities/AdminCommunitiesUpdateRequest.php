<?php

namespace App\Http\Requests\Admin\Communities;

use Illuminate\Foundation\Http\FormRequest;

class AdminCommunitiesUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['sometimes','string','max:255'],
            'description' => ['sometimes','nullable','string'],
            'source' => ['sometimes','nullable','string','max:64'],
            'avatar_url' => ['sometimes','nullable','string','max:2048'],
            'external_id' => ['sometimes','nullable','string','max:255'],
            'city_id' => ['sometimes','nullable','integer','min:1'],
        ];
    }
}
