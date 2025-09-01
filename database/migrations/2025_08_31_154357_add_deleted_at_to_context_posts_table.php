<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void {
        Schema::table('context_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('context_posts', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void {
        Schema::table('context_posts', function (Blueprint $table) {
            if (Schema::hasColumn('context_posts', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
        });
    }
};
