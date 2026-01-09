<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_jobs', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->timestampTz('retry_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('llm_jobs', function (Blueprint $table) {
            $table->dropIndex(['retry_at']);
            $table->dropColumn(['attempt', 'retry_at']);
        });
    }
};
