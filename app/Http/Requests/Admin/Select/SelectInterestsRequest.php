<?php

namespace App\Http\Requests\Admin\Select;

class SelectInterestsRequest extends BaseSelectRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes','string','max:255'],
            'limit' => ['sometimes','integer','min:1','max:50'],

            'id' => ['sometimes','integer','min:1'],
            'ids' => ['sometimes'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeIds('ids');
    }
}
