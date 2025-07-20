<?php

declare(strict_types=1);

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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_post_id')->nullable();
            $table->unsignedBigInteger('community_id');
            $table->string('title');
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('external_url')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('community_id')->references('id')->on('communities')->onDelete('cascade');
            $table->foreign('original_post_id')->references('id')->on('context_posts')->onDelete('set null');
            $table->index('start_time');
            $table->index('status');
            $table->index('city');
        });

        DB::statement('ALTER TABLE events ADD COLUMN location geometry(Point, 4326) NOT NULL;');
        DB::statement('CREATE INDEX events_location_gix ON events USING GIST (location);');
        DB::statement('ALTER TABLE events ADD COLUMN latitude decimal(9,6) GENERATED ALWAYS AS (ST_Y(location::geometry)) STORED');
        DB::statement('ALTER TABLE events ADD COLUMN longitude decimal(9,6) GENERATED ALWAYS AS (ST_X(location::geometry)) STORED');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
