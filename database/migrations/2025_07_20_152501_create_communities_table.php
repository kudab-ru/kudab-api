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
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Название сообщества');
            $table->text('description')->nullable()->comment('Описание сообщества');
            $table->string('source')->nullable()->comment('Источник: vk, tg, site и др.');
            $table->string('avatar_url')->nullable()->comment('Ссылка на аватар');
            $table->string('external_id')->nullable()->comment('ID/slug в исходной соцсети');
            $table->timestamps();

            $table->index('source');
            $table->index('external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};
