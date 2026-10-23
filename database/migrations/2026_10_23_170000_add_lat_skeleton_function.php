<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Латинская запись текста для поиска: «Попкорн Драма» и «popcorn drama» дают одно и то же «popkorn drama».
 * Запрос сводится той же функцией, поэтому правила живут только здесь.
 */
return new class extends Migration
{
    // расширение ставится вне транзакции: его отказ не должен откатить функцию
    public $withinTransaction = false;

    public function up(): void
    {
        // опечатки в поиске площадок, см. `SearchMatch::typo`; без расширения поиск остаётся без опечаток
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS fuzzystrmatch');
        } catch (\Throwable $e) {
            report($e);
        }

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.lat_skeleton(t text)
RETURNS text
LANGUAGE sql
IMMUTABLE
AS $f$
SELECT translate(
  regexp_replace(regexp_replace(regexp_replace(
    replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(
      replace(replace(replace(replace(replace(replace(replace(replace(
        lower(translate(coalesce(t, ''), 'Ёё', 'Ее')),
        'щ', 'sh'), 'ш', 'sh'), 'ч', 'ch'), 'ж', 'z'), 'ц', 'ts'), 'х', 'h'), 'ю', 'u'), 'я', 'a'),
      'ya', 'a'), 'yu', 'u'), 'yo', 'o'), 'ye', 'e'),
      'ck', 'k'), 'ph', 'f'), 'qu', 'kv'), 'kh', 'h'), 'zh', 'z'), 'x', 'ks'),
    'th', 't', 'g'),
  'c([eiy])', 's\1', 'g'),
  'c(?!h)', 'k', 'g'),
  'абвгдезийклмнопрстуфыэqwyjъь',
  'abvgdeziiklmnoprstufiekvii')
$f$;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.lat_skeleton(text)');
    }
};
