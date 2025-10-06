<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE tg_broadcast_rules
              ALTER COLUMN cities         TYPE jsonb USING COALESCE(cities,'[]'::json)::jsonb,
              ALTER COLUMN interest_slugs TYPE jsonb USING COALESCE(interest_slugs,'[]'::json)::jsonb
        ");

        DB::statement("
            ALTER TABLE tg_broadcast_rules
              ALTER COLUMN cities         SET DEFAULT '[]'::jsonb,
              ALTER COLUMN interest_slugs SET DEFAULT '[]'::jsonb
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE tg_broadcast_rules
              ALTER COLUMN cities         TYPE json USING cities::json,
              ALTER COLUMN interest_slugs TYPE json USING interest_slugs::json
        ");

        DB::statement("
            ALTER TABLE tg_broadcast_rules
              ALTER COLUMN cities         SET DEFAULT '[]'::json,
              ALTER COLUMN interest_slugs DROP DEFAULT
        ");
    }
};
