<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS telegram');

        Schema::create('telegram.city_channels', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('city_id')
                ->comment('FK на cities.id');

            $table->string('telegram_url', 255)
                ->comment('Публичная ссылка на канал/чат: https://t.me/...');

            $table->string('telegram_username', 128)
                ->nullable()
                ->comment('Username без @, если есть');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Активна ли привязка');

            $table->boolean('is_default')
                ->default(false)
                ->comment('Фолбэк-канал по умолчанию, если у города нет своей привязки');

            $table->timestamps();

            $table->foreign('city_id', 'telegram_city_channels_city_fk')
                ->references('id')
                ->on('cities')
                ->onDelete('cascade');

            // одна публичная TG-привязка на город
            $table->unique('city_id', 'telegram_city_channels_city_uq');

            $table->index(['is_active', 'is_default'], 'telegram_city_channels_active_default_idx');
        });

        // один default на всю таблицу
        DB::statement(
            'CREATE UNIQUE INDEX telegram_city_channels_default_true_uq
             ON telegram.city_channels (is_default)
             WHERE is_default = true'
        );

        // username уникален без учёта регистра
        DB::statement(
            'CREATE UNIQUE INDEX telegram_city_channels_username_ci_uq
             ON telegram.city_channels (lower(telegram_username))
             WHERE telegram_username IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS telegram.telegram_city_channels_username_ci_uq');
        DB::statement('DROP INDEX IF EXISTS telegram.telegram_city_channels_default_true_uq');

        Schema::dropIfExists('telegram.city_channels');
    }
};
