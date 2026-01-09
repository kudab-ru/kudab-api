<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('price_status', 32)->default('unknown');
            $table->integer('price_min')->nullable();
            $table->integer('price_max')->nullable();
            $table->string('price_currency', 8)->nullable();
            $table->string('price_text', 255)->nullable();
            $table->text('price_url')->nullable();

            $table->index('price_status');
            $table->index('price_min');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['price_status']);
            $table->dropIndex(['price_min']);

            $table->dropColumn([
                'price_status','price_min','price_max','price_currency','price_text','price_url'
            ]);
        });
    }
};
