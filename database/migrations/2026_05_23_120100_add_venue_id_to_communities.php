<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связь community → venue (N:1). venue_host community ссылается на свою
 * площадку, aggregator community — venue_id NULL.
 *
 * ON DELETE SET NULL: удаление venue не должно каскадно удалять community
 * (community может пережить venue или быть переподвязана к другой).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->unsignedBigInteger('venue_id')->nullable()->after('city_id');

            $table->foreign('venue_id')
                ->references('id')->on('venues')
                ->onDelete('set null');

            $table->index('venue_id', 'communities_venue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->dropForeign(['venue_id']);
            $table->dropIndex('communities_venue_idx');
            $table->dropColumn('venue_id');
        });
    }
};
