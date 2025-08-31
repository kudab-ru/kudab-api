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
        if (Schema::hasColumn('interests', 'is_paid')) {
            Schema::table('interests', function (Blueprint $t) {
                $t->dropColumn('is_paid');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('interests', 'is_paid')) {
            Schema::table('interests', function (Blueprint $t) {
                $t->boolean('is_paid')->default(false)->comment('Платный интерес?');
            });
            // На всякий случай выставим false существующим строкам (если СУБД не проставила дефолт)
            DB::statement("UPDATE interests SET is_paid = false WHERE is_paid IS NULL");
        }
    }
};
