<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// «Импровизация» у нас чаще джаз и музыкальная гостиная, чем комедия. Сидер правится вместе
// с этой миграцией: она нужна базам, где сидер повторно не запускают.
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('interests')->whereIn('slug', ['standup', 'cinema', 'sport'])->pluck('id', 'slug');

        if (isset($ids['standup'])) {
            DB::table('interest_aliases')->where('interest_id', $ids['standup'])->where('alias', 'импровизация')->delete();
        }

        $now = now();
        foreach (['cinema' => 'показ фильма', 'sport' => 'массовое катание'] as $slug => $alias) {
            if (isset($ids[$slug])) {
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
