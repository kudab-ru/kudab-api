<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram.message_templates', function (Blueprint $table) {
            $table->bigIncrements('id')
                ->comment('Первичный ключ');

            $table->string('code', 64)
                ->comment('Системный код шаблона (например: basic, brief, promo)');

            $table->string('locale', 8)
                ->default('ru')
                ->comment('Язык шаблона (например: ru, en)');

            $table->string('name')
                ->comment('Человекочитаемое название шаблона для админки');

            $table->text('description')
                ->nullable()
                ->comment('Краткое описание/подсказка по назначению шаблона');

            $table->text('body')
                ->comment('Текст шаблона с плейсхолдерами (HTML + {title}, {start_time|human} и т.п.)');

            $table->boolean('show_images')
                ->default(true)
                ->comment('Показывать ли изображения вместе с этим шаблоном');

            $table->unsignedTinyInteger('max_images')
                ->default(3)
                ->comment('Максимальное количество изображений для этого шаблона');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Флаг активности шаблона (можно ли его выбирать в настройках)');

            $table->timestamps();

            $table->unique(['code', 'locale'], 'telegram_message_templates_code_locale_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram.message_templates');
    }
};
