<?php

namespace App\Http\Requests\Admin\Communities;

use Illuminate\Foundation\Http\FormRequest;

class AdminCommunitiesVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', 'in:vk,tg,site'],

            'limit_per_source' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'overwrite' => ['sometimes', 'boolean'],
            'clear_aggregator' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['overwrite', 'clear_aggregator'] as $key) {
            if ($this->has($key)) {
                $this->merge([
                    $key => filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->input($key),
                ]);
            }
        }
    }
}
