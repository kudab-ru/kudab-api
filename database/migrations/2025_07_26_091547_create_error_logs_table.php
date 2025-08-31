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
        Schema::create('error_logs', function (Blueprint $table) {
            $table->bigIncrements('id')
                ->comment('Primary key');
            $table->string('type', 64)
                ->comment('Тип ошибки: vk_api, job_error, frozen, ml_error и др.');
            $table->unsignedBigInteger('community_id')->nullable()
                ->comment('ID сообщества, если применимо');
            $table->unsignedBigInteger('community_social_link_id')->nullable()
                ->comment('ID ссылки источника, если применимо');
            $table->string('job', 128)->nullable()
                ->comment('Класс/имя задания (job), в котором возникла ошибка');
            $table->text('error_text')
                ->comment('Текст ошибки');
            $table->string('error_code', 32)->nullable()
                ->comment('Код ошибки, если есть');
            $table->timestamp('logged_at')->useCurrent()
                ->comment('Время записи');
            $table->boolean('resolved')->default(false)
                ->comment('Ошибка решена/неактуальна');
            $table->timestamps();

            $table->index('type');
            $table->index('community_id');
            $table->index('community_social_link_id');
            $table->index(['type', 'job']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
