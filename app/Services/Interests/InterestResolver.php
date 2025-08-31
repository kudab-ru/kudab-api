<?php

namespace App\Services\Interests;

use App\Models\Interest;
use App\Models\InterestAlias;
use Illuminate\Support\Facades\DB;

class InterestResolver
{
    /** @param string[] $inputs */
    public function idsFromStrings(array $inputs): array
    {
        $vals = array_values(array_unique(array_filter(array_map(
            fn($s) => mb_strtolower(trim((string)$s)), $inputs
        ))));
        if (!$vals) return [];

        $bySlug = Interest::query()
            ->select('id','slug')
            ->whereIn(DB::raw('lower(slug)'), $vals)
            ->pluck('id','slug')
            ->all();

        $byAlias = InterestAlias::query()
            ->select('interest_id','alias')
            ->whereIn(DB::raw('lower(alias)'), $vals)
            ->pluck('interest_id','alias')
            ->all();

        return array_values(array_unique(array_merge(
            array_values($bySlug),
            array_values($byAlias)
        )));
    }
}
