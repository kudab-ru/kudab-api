<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник площадок (venues). Шаг 2 backlog-задачи venue_hosts.
 *
 * Venue — это физическое место в городе (театр, клуб, музей, парк),
 * к которому привязаны events. Отделено от communities: одна площадка
 * может иметь N communities в соцсетях (vk-группа + tg-канал + сайт),
 * и сейчас они выглядят как N разных «venue_host» в каталоге.
 *
 * house_fias_id — приоритетный ключ матчинга при резолве. Но не UNIQUE:
 * один FIAS-дом может содержать БЦ с несколькими venues, поэтому
 * unique-constraint строится только по slug в пределах города.
 *
 * source_meta хранит трассировку происхождения (origin), полезно при
 * дебаге backfill'а и при разборе incidents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('city_id');
            $table->string('name');
            // URL-slug в рамках города. Глобальный uniq не нужен — «Театр Оперы»
            // в Москве и Воронеже должны иметь slug=teatr-opery каждый.
            $table->string('slug', 160);

            $table->string('street')->nullable();
            $table->string('house', 50)->nullable();
            // Отображаемый адрес (полный, как у events.address).
            $table->string('address')->nullable();

            // FIAS дома — сильный сигнал матчинга, но НЕ unique:
            // один FIAS = одно здание, в одном здании может быть несколько
            // venues (БЦ с арендаторами). Индекс — для lookup'а в resolver.
            $table->string('house_fias_id', 36)->nullable();

            // Категория площадки (theatre/club/museum/cafe/park/...).
            // Nullable до появления LLM-классификатора (отдельная задача).
            $table->string('kind', 40)->nullable();

            $table->text('description')->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->string('cover_url', 500)->nullable();

            // active — показывается в выдаче; hidden — скрыт; draft — на проверке.
            $table->string('status', 20)->default('active');

            // Трасса происхождения: from_community_id, dadata-ответ при backfill,
            // origin='community'/'events'/'manual', LLM-классификация и т.п.
            $table->jsonb('source_meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');

            $table->index(['city_id', 'status'], 'venues_city_status_idx');
        });

        // Geometry-колонка + сгенерированные lat/lon по образцу events.
        DB::statement('ALTER TABLE venues ADD COLUMN location geometry(Point, 4326) NULL');
        DB::statement('CREATE INDEX venues_location_gix ON venues USING GIST (location)');
        DB::statement('ALTER TABLE venues ADD COLUMN latitude decimal(9,6) GENERATED ALWAYS AS (ST_Y(location::geometry)) STORED');
        DB::statement('ALTER TABLE venues ADD COLUMN longitude decimal(9,6) GENERATED ALWAYS AS (ST_X(location::geometry)) STORED');

        // FIAS lookup — частичный индекс по непустым значениям.
        DB::statement('CREATE INDEX venues_house_fias_idx ON venues (house_fias_id) WHERE house_fias_id IS NOT NULL');

        // (city_id, slug) UNIQUE с учётом soft-delete: удалённые не блокируют новые.
        DB::statement('CREATE UNIQUE INDEX venues_city_slug_uniq ON venues (city_id, slug) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
