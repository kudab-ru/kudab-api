<?php

namespace App\Http\Requests\Admin\Select;

class SelectCommunitiesRequest extends BaseSelectRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes','string','max:255'],
            'limit' => ['sometimes','integer','min:1','max:50'],

            'city_id' => ['sometimes','integer','min:1'],

            'with_deleted' => ['sometimes','boolean'],
            'only_deleted' => ['sometimes','boolean'],

            'id' => ['sometimes','integer','min:1'],
            'ids' => ['sometimes'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeIds('ids');
        $this->normalizeBooleans(['with_deleted','only_deleted']);
    }
}
