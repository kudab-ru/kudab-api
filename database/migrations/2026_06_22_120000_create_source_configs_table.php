<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Конфиг внешних structured-источников (Я.Афиша и т.п.), редактируемый из
 * админки суперадмином. Раньше жил статично в parser config/yandex_afisha.php;
 * чтобы админка управляла поведением парсера — бизнес-тумблеры переезжают в
 * общую БД (parser читает их свежим, см. PR3). Секреты (user_agent,
 * headless_endpoint, таймауты) ОСТАЮТСЯ в env — здесь только бизнес-настройки.
 *
 * Ключ строки — (source_slug, city_slug): source_slug = social_networks.slug
 * ('yandex_afisha'), city_slug = external_community_id / cities.slug ('voronezh').
 *
 * Владелец схемы общей БД — kudab-api (у parser своего database/ нет). Пишет в
 * таблицу только api (admin-эндпоинты PR4), parser читает.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_configs')) {
            return;
        }

        Schema::create('source_configs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('source_slug', 32);
            $table->string('city_slug', 64);

            $table->boolean('enabled')->default(false);
            $table->boolean('json_ld_bypass_enabled')->default(false);

            $table->unsignedInteger('listing_limit_per_run')->default(200);
            $table->unsignedInteger('listing_limit_per_section')->default(40);

            // sections: [{slug, content_kind?, audience?, enabled}] — разделы
            // listing'а источника. Валидация slug/enum — на стороне api (PR4).
            $table->jsonb('sections')->nullable();

            // Флаг «запустить сейчас» (PR6, путь A: parser-scheduler подхватывает).
            $table->timestamp('run_requested_at')->nullable();

            $table->timestamps();

            $table->unique(['source_slug', 'city_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_configs');
    }
};
