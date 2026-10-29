<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // `pending` — ждём строку в описании группы ВК, `manual` — заявка у нас, `confirmed` — площадка подтверждена
            $table->string('status', 20)->default('pending');
            $table->string('code', 20);
            $table->string('vk_url', 512)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['venue_id', 'user_id']);
            $table->index(['venue_id', 'status']);
        });

        // заявка «площадка ваша?» из кулис: «подключили» в админке подтверждает эту запись
        Schema::table('organizer_leads', function (Blueprint $table) {
            $table->foreignId('venue_manager_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizer_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venue_manager_id');
        });
        Schema::dropIfExists('venue_managers');
    }
};
