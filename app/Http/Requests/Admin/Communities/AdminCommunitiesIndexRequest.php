<?php

namespace App\Http\Requests\Admin\Communities;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCommunitiesIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','min:1','max:100'],

            'q' => ['sometimes','string','max:255'],
            'city_id' => ['sometimes','integer','min:1'],
            'verification_status' => ['sometimes','string','max:64'],

            'with_deleted' => ['sometimes','boolean'],
            'only_deleted' => ['sometimes','boolean'],

            'sort' => ['sometimes', Rule::in(['id','name','created_at','updated_at','last_checked_at'])],
            'dir'  => ['sometimes', Rule::in(['asc','desc'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['with_deleted','only_deleted'] as $k) {
            if ($this->has($k)) {
                $this->merge([$k => filter_var($this->input($k), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
            }
        }
    }
}
