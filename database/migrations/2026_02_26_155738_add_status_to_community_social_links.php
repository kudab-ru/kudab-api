<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('community_social_links', function (Blueprint $table) {
            $table->string('status', 16)->default('active'); // active|gray|black
            $table->index('status', 'community_social_links_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('community_social_links', function (Blueprint $table) {
            $table->dropIndex('community_social_links_status_idx');
            $table->dropColumn('status');
        });
    }
};
