<?php

namespace App\Http\Requests\Admin\Events;

use Illuminate\Foundation\Http\FormRequest;

class AdminEventsStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['required','string','max:255'],
            'description' => ['nullable','string'],

            'community_id' => ['nullable','integer','min:1'],
            'city_id' => ['nullable','integer','min:1'],

            'address' => ['nullable','string','max:1024'],
            'city' => ['nullable','string','max:255'],

            'start_time' => ['nullable','date'],
            'end_time' => ['nullable','date'],
            'start_date' => ['nullable','date'],

            'time_precision' => ['nullable','string','max:32'],
            'time_text' => ['nullable','string','max:255'],
            'timezone' => ['nullable','string','max:64'],

            'price_status' => ['nullable','string','max:32'],
            'price_min' => ['nullable','integer','min:0'],
            'price_max' => ['nullable','integer','min:0'],
            'price_currency' => ['nullable','string','max:16'],
            'price_text' => ['nullable','string','max:255'],
            'price_url' => ['nullable','string','max:2048'],

            'external_url' => ['nullable','string','max:2048'],
            'status' => ['nullable','string','max:64'],

            'latitude' => ['nullable','numeric','between:-90,90'],
            'longitude' => ['nullable','numeric','between:-180,180'],
            'house_fias_id' => ['nullable','string','max:255'],
            'original_post_id' => ['nullable','integer','min:1'],

            'interests' => ['sometimes','array'],
            'interests.*' => ['integer','min:1'],
        ];
    }
}
