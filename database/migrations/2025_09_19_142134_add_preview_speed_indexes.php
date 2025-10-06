<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // events(status, start_time)
        Schema::table('events', function (Blueprint $t) {
            $t->index(['status', 'start_time'], 'idx_events_status_time');
        });

        // events(lower(city)) — expression index, через raw SQL
        DB::statement("CREATE INDEX IF NOT EXISTS idx_events_city_lower ON events ((lower(city)))");

        // event_interest(event_id)
        Schema::table('event_interest', function (Blueprint $t) {
            $t->index('event_id', 'idx_event_interest_event');
        });

        // interests(slug)
        Schema::table('interests', function (Blueprint $t) {
            $t->index('slug', 'idx_interests_slug');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->dropIndex('idx_events_status_time');
        });

        DB::statement("DROP INDEX IF EXISTS idx_events_city_lower");

        Schema::table('event_interest', function (Blueprint $t) {
            $t->dropIndex('idx_event_interest_event');
        });

        Schema::table('interests', function (Blueprint $t) {
            $t->dropIndex('idx_interests_slug');
        });
    }
};
