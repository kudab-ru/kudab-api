<?php

declare(strict_types=1);

namespace App\Http\Requests\Kulisy;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'нужна почта',
            'email.email' => 'похоже, в почте опечатка',
            'password.required' => 'нужен пароль',
            'password.min' => 'пароль от 8 знаков',
        ];
    }
}
