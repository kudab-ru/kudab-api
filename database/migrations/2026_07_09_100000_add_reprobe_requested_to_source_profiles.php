<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Админка источников PR1: кнопка «Re-probe» ставит метку, парсер забирает
// (parser:sources:consume-reprobes каждые 10 мин) — тот же паттерн
// «api пишет, parser читает свежим», что у source_configs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_profiles', function (Blueprint $table) {
            $table->timestamp('reprobe_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('source_profiles', function (Blueprint $table) {
            $table->dropColumn('reprobe_requested_at');
        });
    }
};
