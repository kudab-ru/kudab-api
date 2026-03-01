<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_groups', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('community_id');
            $table->unsignedBigInteger('city_id')->nullable();

            // вычисляемый ключ группы (MVP): city_id + community_id + title_norm + place_key
            // 190 — безопасно под индекс в postgres
            $table->string('group_key', 190);

            // нормализованное название (для дебага/аналитики и будущего поиска)
            $table->string('title_norm', 255);

            // “место” для ключа (предпочтительно fias, иначе округлённое geo)
            $table->string('house_fias_id', 36)->nullable();
            $table->decimal('lat_round', 9, 3)->nullable();
            $table->decimal('lon_round', 9, 3)->nullable();

            // выбранное “актуальное” событие для карточки (обновляется daily job)
            $table->unsignedBigInteger('current_event_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['community_id', 'city_id'], 'event_groups_comm_city_idx');
            $table->index('city_id', 'event_groups_city_idx');
            $table->index('current_event_id', 'event_groups_current_event_idx');

            $table->foreign('community_id')
                ->references('id')->on('communities')
                ->onDelete('cascade');

            $table->foreign('city_id')
                ->references('id')->on('cities')
                ->onDelete('set null');

            $table->foreign('current_event_id')
                ->references('id')->on('events')
                ->onDelete('set null');
        });

        // partial unique как в events.uniq_events_dedup_active
        DB::statement("CREATE UNIQUE INDEX event_groups_group_key_active_uniq ON event_groups (group_key) WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS event_groups_group_key_active_uniq");
        Schema::dropIfExists('event_groups');
    }
};
