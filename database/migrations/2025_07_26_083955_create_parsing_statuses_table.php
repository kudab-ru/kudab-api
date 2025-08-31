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
        Schema::create('parsing_statuses', function (Blueprint $table) {
            $table->bigIncrements('id')
                ->comment('Primary key: статус парсинга для каждой community_social_link');
            $table->unsignedBigInteger('community_social_link_id')
                ->comment('FK на community_social_links.id — источник парсинга');
            $table->boolean('is_frozen')->default(false)
                ->comment('Флаг: источник заморожен для парсинга (лимиты, ошибки, капча)');
            $table->string('frozen_reason', 64)->nullable()
                ->comment('Причина заморозки: rate_limit, ban, captcha, error, manual');
            $table->timestamp('unfreeze_at')->nullable()
                ->comment('Время автоматического размораживания');
            $table->text('last_error')->nullable()
                ->comment('Текст последней ошибки');
            $table->string('last_error_code', 16)->nullable()
                ->comment('Код ошибки (429, 403, 500, timeout и др.)');
            $table->timestamp('last_success_at')->nullable()
                ->comment('Время последнего успешного парсинга');
            $table->integer('total_failures')->default(0)
                ->comment('Число неудачных попыток подряд');
            $table->integer('retry_count')->default(0)
                ->comment('Количество подряд ретраев после последнего успеха');
            $table->timestamps();

            // Индекс для healthcheck: по заморозке и дате разморозки
            $table->index(['is_frozen', 'unfreeze_at'], 'parsing_statuses_frozen_unfreeze_index');
            // Уникальность: только одна запись на каждую ссылку
            $table->unique(['community_social_link_id'], 'parsing_statuses_link_unique');

            // Внешний ключ для целостности
            $table->foreign('community_social_link_id', 'parsing_statuses_link_fk')
                ->references('id')->on('community_social_links')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parsing_statuses');
    }
};
