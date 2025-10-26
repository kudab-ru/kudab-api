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
        // 0) Гарантируем PostGIS
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        // 1) База полей
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('country_code', 2)->nullable(); // ISO-2
            $table->timestamps();
        });

        // 2) Геоколонка + индексы (в стиле events)
        DB::statement('ALTER TABLE cities ADD COLUMN location geometry(Point, 4326) NOT NULL;');
        DB::statement('CREATE INDEX cities_location_gix ON cities USING GIST (location);');
        DB::statement('ALTER TABLE cities ADD COLUMN latitude  decimal(9,6) GENERATED ALWAYS AS (ST_Y(location::geometry)) STORED;');
        DB::statement('ALTER TABLE cities ADD COLUMN longitude decimal(9,6) GENERATED ALWAYS AS (ST_X(location::geometry)) STORED;');

        // 3) Уникальность: страна+имя (CI)
        DB::statement("CREATE UNIQUE INDEX cities_country_name_unique ON cities (COALESCE(country_code,'??'), lower(name));");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
