<?php

namespace App\Http\Requests\Admin\Events;

use Illuminate\Foundation\Http\FormRequest;

class AdminEventsUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['sometimes','string','max:255'],
            'description' => ['sometimes','nullable','string'],

            'community_id' => ['sometimes','nullable','integer','min:1'],
            'city_id' => ['sometimes','nullable','integer','min:1'],

            'address' => ['sometimes','nullable','string','max:1024'],
            'city' => ['sometimes','nullable','string','max:255'],

            'start_time' => ['sometimes','nullable','date'],
            'end_time' => ['sometimes','nullable','date'],
            'start_date' => ['sometimes','nullable','date'],

            'time_precision' => ['sometimes','nullable','string','max:32'],
            'time_text' => ['sometimes','nullable','string','max:255'],
            'timezone' => ['sometimes','nullable','string','max:64'],

            'price_status' => ['sometimes','nullable','string','max:32'],
            'price_min' => ['sometimes','nullable','integer','min:0'],
            'price_max' => ['sometimes','nullable','integer','min:0'],
            'price_currency' => ['sometimes','nullable','string','max:16'],
            'price_text' => ['sometimes','nullable','string','max:255'],
            'price_url' => ['sometimes','nullable','string','max:2048'],

            'external_url' => ['sometimes','nullable','string','max:2048'],
            'status' => ['sometimes','nullable','string','max:64'],

            'latitude' => ['sometimes','nullable','numeric','between:-90,90'],
            'longitude' => ['sometimes','nullable','numeric','between:-180,180'],
            'house_fias_id' => ['sometimes','nullable','string','max:255'],
            'original_post_id' => ['sometimes','nullable','integer','min:1'],

            'interests' => ['sometimes','array'],
            'interests.*' => ['integer','min:1'],
        ];
    }
}
