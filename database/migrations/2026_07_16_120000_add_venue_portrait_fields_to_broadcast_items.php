<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Портрет площадки как пост в очереди рассылки.
 *
 * Очередь была строго событийной (event_id NOT NULL). Добавляем второй тип
 * поста — «портрет площадки» — не трогая событийный путь: он едет по ТЕМ ЖЕ
 * рельсам (claim-lease / ревью-гейт / анти-дубль / отправка ботом).
 *
 *  - kind      — 'event' (дефолт, старые ряды) | 'venue';
 *  - event_id  — становится nullable (у venue-поста события нет);
 *  - venue_id  — площадка для kind=venue;
 *  - caption   — готовый текст поста (venue рендерится не из шаблона, а заранее);
 *  - photo_url — обложка (прокси из событий площадки, своих фото у venue нет).
 *
 * Всё аддитивно/nullable — безопасно на горячей таблице.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->string('kind', 16)->default('event')->after('broadcast_id')
                ->comment('event | venue — тип поста в очереди');
            $table->unsignedBigInteger('venue_id')->nullable()->after('event_id')
                ->comment('Для kind=venue: площадка (venues.id). Для event — NULL');
            $table->text('caption')->nullable()->after('venue_id')
                ->comment('Готовый текст поста для kind=venue (event бот рендерит из шаблона)');
            $table->string('photo_url', 1000)->nullable()->after('caption')
                ->comment('Обложка поста для kind=venue (прокси из событий площадки)');

            $table->foreign('venue_id', 'chat_broadcast_items_venue_fk')
                ->references('id')->on('venues')->nullOnDelete();

            $table->index(['broadcast_id', 'kind', 'status'], 'cbi_broadcast_kind_status_idx');
        });

        // event_id теперь nullable: у портрета площадки события нет. UNIQUE(broadcast_id,
        // event_id) остаётся — в Postgres несколько NULL считаются различными, поэтому
        // venue-ряды (event_id NULL) не конфликтуют.
        DB::statement('ALTER TABLE telegram.chat_broadcast_items ALTER COLUMN event_id DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropForeign('chat_broadcast_items_venue_fk');
            $table->dropIndex('cbi_broadcast_kind_status_idx');
            $table->dropColumn(['kind', 'venue_id', 'caption', 'photo_url']);
        });

        // best-effort: вернуть NOT NULL (упадёт, если остались venue-ряды с NULL event_id)
        DB::statement('ALTER TABLE telegram.chat_broadcast_items ALTER COLUMN event_id SET NOT NULL');
    }
};
