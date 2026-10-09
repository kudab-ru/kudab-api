<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Сидер правится вместе с этой миграцией: она нужна базам, где сидер повторно не запускают.
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('interests')->whereIn('slug', ['parties', 'circus-show', 'sport', 'quiz-games'])->pluck('id', 'slug');

        if (isset($ids['parties'])) {
            DB::table('interest_aliases')->where('interest_id', $ids['parties'])->where('alias', 'танго')->delete();
        }

        $now = now();
        $add = [
            'circus-show' => ['танцевальное шоу', 'ансамбль танца'],
            'sport' => ['скалодром'],
            'quiz-games' => ['настольный'],
        ];
        foreach ($add as $slug => $aliases) {
            foreach (isset($ids[$slug]) ? $aliases : [] as $alias) {
                DB::table('interest_aliases')->insertOrIgnore([
                    'interest_id' => $ids[$slug],
                    'alias' => $alias,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void {}
};
