<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('interest_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('interest_id')->constrained('interests')->cascadeOnDelete();
            $t->string('alias', 64)->comment('Синоним / альтернативный ярлык');
            $t->string('locale', 8)->nullable()->comment('ru/en/... (опционально)');
            $t->timestamps();

            $t->index('interest_id');
        });

        // Кейс-инсенситивный UNIQUE по alias
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS interest_aliases_alias_unique ON interest_aliases (lower(alias))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS interest_aliases_alias_unique");
        Schema::dropIfExists('interest_aliases');
    }
};
