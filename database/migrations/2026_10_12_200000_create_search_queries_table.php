<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Что ищут в афише на сайте и сколько находят. Пишет `SearchLog`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->string('q', 255);
            $table->unsignedBigInteger('city_id')->nullable();
            // то же число, что человек видит в строке «Найдено N событий»
            $table->unsignedInteger('found');
            // остальные фильтры запроса; с ними ноль найденного ещё не значит, что поиск плох
            $table->jsonb('filters')->nullable();
            // посетитель в пределах суток, см. `SearchLog`
            $table->string('visitor', 16)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
