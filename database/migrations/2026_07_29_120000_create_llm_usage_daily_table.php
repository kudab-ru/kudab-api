<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Суточный расход LLM в разрезе «назначение × модель». Пишет ТОЛЬКО парсер
 * (App\Services\LLM\Telemetry\LlmUsageRecorder, один INSERT ... ON CONFLICT на вызов),
 * читают отчёт `parser:llm:usage` и админка.
 *
 * Зачем таблица, а не лог: расход текст-движка сегодня не виден нигде — usage от
 * провайдера выбрасывался, а ключ ProxyAPI общий с OpenAI-путём, поэтому в биллинге
 * дорогой Sonnet и дешёвый gpt-4o-mini неразделимы. Без опорных суток «до» нельзя
 * доказать ни экономию от смены модели, ни цену регенераций гейтов.
 *
 * Гранулярность суточная: строк ~десятки в день, история дешёвая, а вопросы к ней
 * ровно суточные («сколько за неделю», «что подорожало после флипа»). Поштучные
 * вызовы писать смысла нет — их тысячи, и ни один вопрос к ним не адресуется.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('llm_usage_daily')) {
            return;
        }

        Schema::create('llm_usage_daily', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->date('day');
            // bulk|tg (текст-движок) + extract|classify|dedup_verify|taxonomy|followup|other
            $table->string('purpose', 40);
            $table->string('model', 64);

            $table->unsignedInteger('calls')->default(0);
            // повторные вызовы по одной сущности: цена гейт-петли, отдельной строкой
            $table->unsignedInteger('retry_calls')->default(0);
            $table->unsignedInteger('errors')->default(0);

            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);

            // в микродолларах: считаем на стороне парсера по прайсу из config/llm_text.php,
            // чтобы отчёт не пересчитывал историю после смены цен у провайдера
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedBigInteger('latency_ms_sum')->default(0);

            $table->timestamps();

            $table->unique(['day', 'purpose', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_usage_daily');
    }
};
