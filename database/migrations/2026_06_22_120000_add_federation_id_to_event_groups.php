<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-community федерация EventGroup'ов (MVP-B).
 *
 * Проблема: group_key = sha1(city|COMMUNITY|title_norm|place) — community_id зашит,
 * поэтому одно логическое событие из N пабликов = N групп = N карточек на ленте
 * (классика: фестиваль из 4 сообществ). Cross-dedup их намеренно НЕ сливает
 * (LLM: «зонтик из разных сообществ = не дубль»).
 *
 * Решение: federation_id связывает группы разных сообществ в одну «супергруппу»
 * БЕЗ мёржа событий. Указывает на каноническую event_groups.id федерации
 * (self-ref). NULL = группа не федерирована (поведение как раньше).
 *
 * Read-путь (EventRepository) схлопывает по COALESCE(federation_id, event_group_id, -id),
 * поэтому колонка инертна, пока матчер (parser PR2) её не наполнит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('federation_id')->nullable()->after('group_key');

            $table->index('federation_id', 'event_groups_federation_idx');

            // self-ref на каноническую группу федерации; при удалении канона
            // члены раз-федерируются (federation_id → NULL), не каскадно дропаются.
            $table->foreign('federation_id')
                ->references('id')->on('event_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_groups', function (Blueprint $table) {
            $table->dropForeign(['federation_id']);
            $table->dropIndex('event_groups_federation_idx');
            $table->dropColumn('federation_id');
        });
    }
};
