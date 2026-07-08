<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Профили структурных сайтов-источников (само-строящийся парсер, PR1).
// Профиль = конкретная краулимая единица «источник × город»: листинг + регэксп
// event-ссылок. Читает kudab-parser (SiteJsonLdApiService, network id=3 'site');
// строки создаёт parser:sources:probe --save либо руками. Новый JSON-LD сайт =
// одна строка, без кода.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_profiles', function (Blueprint $table) {
            $table->id();
            // slug профиля = external_community_id у community_social_links (network 3)
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('listing_url', 500);
            // PCRE по АБСОЛЮТНОМУ URL без query/fragment (якоря обязательны)
            $table->string('event_url_regex', 500);
            $table->string('wait_selector', 300)->nullable();
            $table->string('city_slug', 100)->nullable();
            $table->boolean('enabled')->default(true);
            // overrides: delay_ms, listing_limit, listing_timeout_ms, detail_timeout_ms, user_agent
            $table->json('settings')->nullable();
            // результаты последнего probe: sample, hit-rate, кандидаты
            $table->json('probe_meta')->nullable();
            $table->timestamp('probed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_profiles');
    }
};
