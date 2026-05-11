<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\CommunityLinks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['active', 'gray', 'black'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
