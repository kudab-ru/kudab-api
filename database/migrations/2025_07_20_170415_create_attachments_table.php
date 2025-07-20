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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('parent_type')->comment('Тип родительского объекта: context_post, event и др.');
            $table->unsignedBigInteger('parent_id')->comment('ID родительского объекта');
            $table->string('type')->comment('Тип вложения: image, video, file и др.');
            $table->string('url')->comment('Ссылка на файл');
            $table->string('preview_url')->nullable()->comment('Ссылка на превью (если есть)');
            $table->integer('order')->default(0)->comment('Порядок вложения');
            $table->timestamps();

            $table->index(['parent_type', 'parent_id']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
