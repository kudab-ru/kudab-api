<?php

namespace App\Http\Requests\Admin\Select;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseSelectRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function normalizeIds(string $key = 'ids'): void
    {
        if (!$this->has($key)) return;

        $raw = $this->input($key);

        if (is_string($raw)) {
            // ids=5,3
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }

        if (!is_array($raw)) {
            $this->merge([$key => []]);
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn ($v) => $v > 0)));
        $this->merge([$key => $ids]);
    }

    protected function normalizeBooleans(array $keys): void
    {
        foreach ($keys as $k) {
            if ($this->has($k)) {
                $this->merge([$k => filter_var($this->input($k), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
            }
        }
    }
}
