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
        // status
        Schema::table('cities', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->string('status', 16)->default('active')->after('country_code'); // active|disabled|limited
            $t->index('status', 'cities_status_idx');
        });

        // name_ci (CI-уникальность по имени)
        DB::statement("ALTER TABLE cities ADD COLUMN name_ci text GENERATED ALWAYS AS (lower(name)) STORED");

        // если был прежний индекс на выражении — удалим, он мешать не будет, но и не нужен
        DB::statement("DO $$ BEGIN
            IF EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'cities_country_name_unique') THEN
                EXECUTE 'DROP INDEX cities_country_name_unique';
            END IF;
        END $$;");

        // строгая уникальность по стране+имя_ci
        DB::statement("ALTER TABLE cities ADD CONSTRAINT cities_country_name_ci_uniq UNIQUE (country_code, name_ci)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE cities DROP CONSTRAINT IF EXISTS cities_country_name_ci_uniq");
        DB::statement("ALTER TABLE cities DROP COLUMN IF EXISTS name_ci");
        Schema::table('cities', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->dropIndex('cities_status_idx');
            $t->dropColumn('status');
        });
    }
};
