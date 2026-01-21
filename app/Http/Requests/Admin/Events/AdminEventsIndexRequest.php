<?php

namespace App\Http\Requests\Admin\Events;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminEventsIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // доступ уже ограничен middleware
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','min:1','max:100'],

            'q' => ['sometimes','string','max:255'],
            'city_id' => ['sometimes','integer','min:1'],
            'community_id' => ['sometimes','integer','min:1'],
            'status' => ['sometimes','string','max:64'],

            'date_from' => ['sometimes','date'],
            'date_to' => ['sometimes','date'],
            'free' => ['sometimes','boolean'],

            'interests' => ['sometimes','array'],
            'interests.*' => ['integer','min:1'],

            'with_deleted' => ['sometimes','boolean'],
            'only_deleted' => ['sometimes','boolean'],

            'sort' => ['sometimes', Rule::in(['id','title','start_date','start_time','created_at','updated_at','price_min'])],
            'dir'  => ['sometimes', Rule::in(['asc','desc'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        // boolean из query часто приходит строкой "0"/"1"/"true"/"false"
        foreach (['free','with_deleted','only_deleted'] as $k) {
            if ($this->has($k)) {
                $this->merge([$k => filter_var($this->input($k), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
            }
        }
    }
}
