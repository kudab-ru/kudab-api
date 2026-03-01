<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('event_group_id')->nullable()->after('community_id');

            $table->index('event_group_id', 'events_event_group_id_idx');

            $table->foreign('event_group_id')
                ->references('id')->on('event_groups')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['event_group_id']);
            $table->dropIndex('events_event_group_id_idx');
            $table->dropColumn('event_group_id');
        });
    }
};
