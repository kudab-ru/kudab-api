<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1) Добавляем столбец (временно nullable)
        Schema::table('interests', function (Blueprint $t) {
            if (!Schema::hasColumn('interests', 'slug')) {
                $t->string('slug', 64)->nullable()->comment('Слаг интереса (lower-kebab)');
            }
        });

        // 2) Заполняем slug из name (нижний регистр + дефисы вместо пробелов)
        //    Без транслитерации (оставим на приложение/админку), чтобы миграция была self-contained.
        DB::statement("
            UPDATE interests
            SET slug = trim(both '-' FROM lower(regexp_replace(name, '\\s+', '-', 'g')))
            WHERE slug IS NULL OR slug = ''
        ");

        // 3) Разруливаем возможные дубли (добавляем суффикс -id)
        DB::statement("
            DO $$
            DECLARE r record;
            BEGIN
                FOR r IN
                    SELECT lower(slug) AS s, count(*) AS c
                    FROM interests
                    GROUP BY lower(slug)
                    HAVING count(*) > 1
                LOOP
                    UPDATE interests i
                    SET slug = i.slug || '-' || i.id
                    WHERE lower(i.slug) = r.s;
                END LOOP;
            END$$;
        ");

        // 4) Делаем NOT NULL
        Schema::table('interests', function (Blueprint $t) {
            $t->string('slug', 64)->nullable(false)->change();
        });

        // 5) Кейс-инсенситивная уникальность (PostgreSQL): UNIQUE на lower(slug)
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS interests_slug_unique ON interests (lower(slug))");
        DB::statement("CREATE INDEX IF NOT EXISTS interests_name_idx ON interests (lower(name))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // снимаем индексы и колонку
        DB::statement("DROP INDEX IF EXISTS interests_name_idx");
        DB::statement("DROP INDEX IF EXISTS interests_slug_unique");

        Schema::table('interests', function (Blueprint $t) {
            if (Schema::hasColumn('interests', 'slug')) {
                $t->dropColumn('slug');
            }
        });
    }
};
