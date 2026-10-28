<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Катки города для страницы /afisha/{город}/katki и блока «массовое катание» на странице площадки.
 * Заполняет их разовая задача парсера при выкате, дальше правят в админке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rinks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('city_id');
            $table->unsignedBigInteger('venue_id')->nullable();
            $table->string('slug', 80);
            $table->string('name');
            // как каток назвать в тексте после предлога: «на Адмиралтейской площади», «в «Платинум Арене»»
            $table->string('name_in')->nullable();
            $table->string('address')->nullable();
            $table->string('area', 80)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lon', 9, 6)->nullable();
            $table->boolean('indoor');
            // `artificial` держится и в плюс, `natural` тает; пусто — лёд неизвестен, в оттепель тогда предупреждаем
            $table->string('ice', 16)->nullable();
            $table->string('entry', 16)->default('cash');
            // `closed` ставится только по словам самого катка, погода каток не закрывает
            $table->string('status', 16)->default('open');
            $table->string('status_note')->nullable();
            $table->string('status_short', 40)->nullable();
            $table->string('status_source')->nullable();
            $table->date('opens_on')->nullable();
            $table->jsonb('schedule')->nullable();
            // сеансы на конкретные даты сильнее недельного расписания
            $table->jsonb('sessions')->nullable();
            $table->jsonb('closures')->nullable();
            $table->jsonb('prices')->nullable();
            $table->jsonb('features')->nullable();
            $table->boolean('kids')->default(false);
            $table->jsonb('links')->nullable();
            $table->string('week_note')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->smallInteger('sort')->default(0);
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->unique(['city_id', 'slug']);
            $table->foreign('city_id')->references('id')->on('cities')->cascadeOnDelete();
            $table->foreign('venue_id')->references('id')->on('venues')->nullOnDelete();
            $table->index('venue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rinks');
    }
};
