<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\ParsingStatus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'frozen'   => ['nullable', 'boolean'],
            'reason'   => ['nullable', 'string', Rule::in(['rate_limit', 'ban', 'captcha', 'error', 'manual'])],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
