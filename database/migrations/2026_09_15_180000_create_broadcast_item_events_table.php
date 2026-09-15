<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Связь «пост очереди → события, о которых он рассказывает».
 *
 * ЗАЧЕМ. Сегодня связь выражена одной колонкой `chat_broadcast_items.event_id`,
 * и на вопрос «канал уже показывал это событие?» отвечают пять мест, читая её
 * тремя разными способами. Следующая рубрика канала — подборка недели: один
 * пост про 3-5 событий. В колонку они не поместятся, останутся кандидатами
 * обычной ленты и выйдут по одному в ближайшие дни — ровно та жалоба, ради
 * которой защита от повторов и появилась.
 *
 * ЧЕГО ЗДЕСЬ НАМЕРЕННО НЕТ.
 *
 * 1. КОЛОНКИ `updated_at`. Не стиль, а падение: читатели анти-дублей пишут
 *    `->where('updated_at', '>=', ...)` внутри `whereDoesntHave`, и Laravel
 *    выводит имя БЕЗ квалификации. С такой колонкой в обеих таблицах Postgres
 *    отвечает `column reference "updated_at" is ambiguous`. Общее правило:
 *    в этой таблице не должно быть колонок, которые читатели упоминают
 *    неквалифицированно, — сегодня это `broadcast_id`, `status`, `posted_at`,
 *    `updated_at`. Ни одной здесь нет, и добавлять нельзя.
 *
 * 2. БЭКОФИЛЛА. Он живёт отдельной командой `broadcast:links:backfill`:
 *    миграция выполняется ровно один раз, и после отката на предыдущий пин
 *    заполнить связь было бы нечем. Команда идемпотентна и умеет `--check`.
 *
 * 3. СНЯТИЯ СТАРОГО `UNIQUE(broadcast_id, event_id)`. Он держит гонку в
 *    `enqueue()` (find-then-insert без транзакции) и опознание записи для
 *    оживления в четырёх местах. Подборке он не мешает: у неё `event_id` пуст,
 *    а NULL-строки в UNIQUE не конфликтуют — на этом уже живут портреты
 *    площадок.
 */
return new class extends Migration
{
    public function up(): void
    {
        // FK на `events` берёт ShareRowExclusive на всю таблицу событий, а
        // lock_timeout в базе нулевой: без этой строки миграция молча ждала бы
        // любой долгий запрос по events. Честный отказ лучше немого зависания —
        // prod-deploy на провалившемся migrate прерывается сам.
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::create('telegram.chat_broadcast_item_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('item_id')->comment('telegram.chat_broadcast_items.id');
            $table->unsignedBigInteger('event_id')->comment('events.id — событие, о котором рассказывает пост');
            $table->smallInteger('position')->default(0)
                ->comment('0 — ведущее событие поста; у подборки 1..N — порядок в тексте');
            $table->timestampTz('created_at')->nullable();

            $table->foreign('item_id', 'cbie_item_fk')
                ->references('id')->on('telegram.chat_broadcast_items')->cascadeOnDelete();
            $table->foreign('event_id', 'cbie_event_fk')
                ->references('id')->on('events')->cascadeOnDelete();

            $table->unique(['item_id', 'event_id'], 'cbie_item_event_uq');
            // Направление «от события к постам» — так читают все анти-дубли.
            $table->index(['event_id', 'item_id'], 'cbie_event_item_idx');
        });

        // Ведущее событие у поста ровно одно. На этом держится новый смысл
        // колонки items.event_id («ведущее» = position 0), и без индекса схема
        // допускала бы два ведущих у одной записи.
        DB::statement(
            'CREATE UNIQUE INDEX cbie_item_leader_uq
             ON telegram.chat_broadcast_item_events (item_id) WHERE position = 0'
        );
    }

    /**
     * Обратный ход безопасен ТОЛЬКО пока строки связи — производная от
     * `items.event_id`: их восстанавливает бэкофилл. С появлением записей
     * `kind = digest` эта таблица станет единственным носителем состава
     * подборки, и `down()` начнёт уничтожать данные — заменить на отказ тем же
     * коммитом, которым появится композитор подборки.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram.chat_broadcast_item_events');
    }
};
