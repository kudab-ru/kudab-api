<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История правок поста: состояние ДО каждого изменения.
 *
 * ЗАЧЕМ. Сегодня правка необратима. Кнопка «Вернуть шаблонный текст» есть, но
 * она собирает текст заново по шаблону — то есть возвращает не то, что было, а
 * то, что сгенерировалось бы сейчас. Свой текст, написанный руками и потом
 * случайно затёртый, восстановить нечем вовсе.
 *
 * ЧТО ХРАНИМ. Только то, что человек правит и боится потерять: текст,
 * источник текста и набор картинок. Время публикации НЕ восстанавливаем — оно
 * видно в сетке и правится перетаскиванием, а молчаливый возврат старого дня
 * вытеснил бы соседний пост. Поле `changed` объясняет строку истории: это те
 * поля, которые изменила ПОСЛЕДОВАВШАЯ правка.
 *
 * СКОЛЬКО ХРАНИМ. Последние 20 версий на пост, старое подрезается при записи:
 * история нужна, чтобы отменить ошибку, а не чтобы вести архив.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram.chat_broadcast_item_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('item_id')->comment('telegram.chat_broadcast_items.id');
            $table->text('caption')->nullable()->comment('Текст поста ДО правки');
            $table->string('caption_source', 16)->nullable()->comment('template | manual на тот момент');
            $table->json('photo_urls')->nullable()->comment('Набор картинок ДО правки; NULL — автоподбор');
            $table->json('changed')->nullable()->comment('Какие поля изменила последовавшая правка');
            $table->unsignedBigInteger('user_id')->nullable()->comment('Кто правил, если известно');
            $table->timestampTz('created_at')->nullable();

            $table->foreign('item_id', 'cbir_item_fk')
                ->references('id')->on('telegram.chat_broadcast_items')->cascadeOnDelete();

            $table->index(['item_id', 'id'], 'cbir_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram.chat_broadcast_item_revisions');
    }
};
