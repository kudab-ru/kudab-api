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
        Schema::table('community_social_links', function (Blueprint $table) {
            $table->unsignedBigInteger('last_verification_id')->nullable()->after('updated_at');
            $table->timestamp('last_checked_at')->nullable()->after('last_verification_id');

            $table->boolean('last_is_active')->nullable()->after('last_checked_at');
            $table->boolean('last_has_events')->nullable()->after('last_is_active');
            $table->string('last_kind', 16)->nullable()->after('last_has_events');

            $table->string('last_hq_city')->nullable()->after('last_kind');
            $table->string('last_hq_street')->nullable()->after('last_hq_city');
            $table->string('last_hq_house')->nullable()->after('last_hq_street');
            $table->decimal('last_hq_confidence', 3, 2)->nullable()->after('last_hq_house');

            $table->foreign('last_verification_id')->references('id')->on('social_link_verifications')->nullOnDelete();

            $table->index('last_checked_at', 'community_social_links_last_checked_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('community_social_links', function (Blueprint $table) {
            $table->dropForeign(['last_verification_id']);
            $table->dropIndex('community_social_links_last_checked_at_idx');

            $table->dropColumn([
                'last_verification_id',
                'last_checked_at',
                'last_is_active',
                'last_has_events',
                'last_kind',
                'last_hq_city',
                'last_hq_street',
                'last_hq_house',
                'last_hq_confidence',
            ]);
        });
    }
};
