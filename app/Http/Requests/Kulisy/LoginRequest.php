<?php

declare(strict_types=1);

namespace App\Http\Requests\Kulisy;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }
}
