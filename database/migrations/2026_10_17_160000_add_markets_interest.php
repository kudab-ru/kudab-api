<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Тема «Ярмарки и маркеты»: «ярмарка» и «маркет» уходят из синонимов городских акций в свою тему.
 * Сидер правится вместе с миграцией: она нужна базам, где сидер повторно не запускают.
 */
return new class extends Migration
{
    private const ALIASES = [
        'ярмарка', 'маркет', 'барахолка', 'блошиный рынок', 'гаражка', 'гаражная распродажа', 'фримаркет',
        'фуд-маркет', 'маркет мастеров', 'винтажный маркет', 'хендмейд', 'handmade', 'market', 'своп',
    ];

    public function up(): void
    {
        $now = now();
        $id = DB::table('interests')->where('slug', 'markets')->value('id')
            ?? DB::table('interests')->insertGetId([
                'slug' => 'markets', 'name' => 'Ярмарки и маркеты', 'parent_id' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);

        DB::table('interest_aliases')->whereRaw('lower(alias) in (?, ?)', ['ярмарка', 'маркет'])->delete();
        foreach (self::ALIASES as $alias) {
            DB::table('interest_aliases')->insertOrIgnore([
                'interest_id' => $id, 'alias' => $alias, 'locale' => 'ru', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void {}
};
