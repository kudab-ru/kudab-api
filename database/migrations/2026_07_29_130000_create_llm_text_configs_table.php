<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Активная модель текст-движка по назначению: {"bulk": "...", "tg": "..."}.
 * Единственная строка (key='default'), как у proxy_configs.
 *
 * ПАТТЕРН (как source_configs): пишет ТОЛЬКО api (админка/CLI), читает парсер —
 * TextModelResolver, свежим запросом с 30-секундным кэшем. Смысл именно в этом:
 * прод-флип через env упирается в два .env и force-recreate контейнеров, а правка
 * строки применяется со следующего прогона ночных команд.
 *
 * Стартовое значение — NULL: пока суперадмин ничего не выбрал, парсер идёт на
 * дефолты config/llm_text.php, и миграция ничего не меняет в поведении.
 *
 * Список допустимых моделей — whitelist в парсере (config/llm_text.php). Здесь
 * значение не валидируется на уровне схемы: незнакомая модель гасится резолвером
 * (откат на дефолт + warning), чтобы опечатка не останавливала ночь.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('llm_text_configs')) {
            return;
        }

        Schema::create('llm_text_configs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 32)->default('default');
            $table->jsonb('models')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('key');
        });

        DB::table('llm_text_configs')->insert([
            'key' => 'default',
            'models' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_text_configs');
    }
};
