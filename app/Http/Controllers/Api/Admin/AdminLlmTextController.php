<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LlmText\UpdateLlmTextConfigRequest;
use App\Models\LlmTextConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Какой моделью писать тексты: массовые описания каталога (bulk) и тексты в
 * телеграм-канал (tg). Суперадмин.
 *
 * Api только пишет строку в llm_text_configs; читает её парсер (TextModelResolver)
 * свежим запросом с 30-секундным кэшем — поэтому смена применяется со следующего
 * прогона ночных команд, без деплоя и без перезапуска очередей. Это и есть смысл
 * настройки: прод-флип через env упирается в два .env и force-recreate.
 *
 * Рядом с выбором отдаём фактический расход из llm_usage_daily: решение «дорогая
 * или дешёвая модель» без цифр за прошлую неделю — гадание.
 */
class AdminLlmTextController extends Controller
{
    private const PURPOSES = ['bulk', 'tg'];

    private const USAGE_WINDOW_DAYS = 14;

    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'purposes' => $this->purposes(),
            'models' => $this->models(),
            'usage' => $this->usage(),
            'usage_days' => self::USAGE_WINDOW_DAYS,
        ]]);
    }

    public function update(UpdateLlmTextConfigRequest $request): JsonResponse
    {
        $config = $this->singleton();
        $map = $config->models ?? [];

        foreach (self::PURPOSES as $purpose) {
            if (! $request->has($purpose)) {
                continue;
            }
            $value = $request->input($purpose);
            // «default» и пустое значение = снять ручной выбор, вернуться к дефолту парсера
            if ($value === null || $value === '' || $value === 'default') {
                unset($map[$purpose]);

                continue;
            }
            $map[$purpose] = (string) $value;
        }

        $config->models = $map === [] ? null : $map;
        $config->updated_by = $request->user()?->id;
        $config->save();

        Log::info('admin:llm-text-model-updated', [
            'actor_id' => $request->user()?->id,
            'models' => $map,
        ]);

        return response()->json(['data' => [
            'purposes' => $this->purposes(),
            'models' => $this->models(),
            'usage' => $this->usage(),
            'usage_days' => self::USAGE_WINDOW_DAYS,
        ]]);
    }

    private function singleton(): LlmTextConfig
    {
        return LlmTextConfig::query()->firstOrCreate(['key' => 'default'], ['models' => null]);
    }

    /**
     * Что выбрано по каждому назначению. `null` = ручного выбора нет, работает
     * дефолт парсера — показываем это честно, а не подставляем ожидаемую модель:
     * дефолт живёт в чужом репозитории и может разойтись с нашим представлением.
     *
     * @return list<array{key: string, title: string, hint: string, model: string|null}>
     */
    private function purposes(): array
    {
        $map = $this->singleton()->models ?? [];

        return [
            [
                'key' => 'bulk',
                'title' => 'Массовые описания',
                'hint' => 'Описания событий и площадок для каталога. Их сотни, читает лента сайта.',
                'model' => $map['bulk'] ?? null,
            ],
            [
                'key' => 'tg',
                'title' => 'Тексты в канал',
                'hint' => 'Анонсы и портреты площадок для телеграма. Их единицы в сутки, каждый видит человек.',
                'model' => $map['tg'] ?? null,
            ],
        ];
    }

    /** @return list<array{id: string, label: string, price_in: float|null, price_out: float|null}> */
    private function models(): array
    {
        $out = [];
        foreach ((array) config('llm_text_models', []) as $id => $spec) {
            $out[] = [
                'id' => (string) $id,
                'label' => (string) ($spec['label'] ?? $id),
                'price_in' => isset($spec['price_in']) ? (float) $spec['price_in'] : null,
                'price_out' => isset($spec['price_out']) ? (float) $spec['price_out'] : null,
            ];
        }

        return $out;
    }

    /**
     * Фактический расход по назначениям за окно. Таблицы может не быть (миграция
     * не накатана) — отдаём пустоту, форма от этого не ломается.
     *
     * @return list<array{purpose: string, calls: int, retry_calls: int, errors: int, cost_usd: float, models: string}>
     */
    private function usage(): array
    {
        if (! Schema::hasTable('llm_usage_daily')) {
            return [];
        }

        return DB::table('llm_usage_daily')
            ->where('day', '>=', now()->subDays(self::USAGE_WINDOW_DAYS)->toDateString())
            ->groupBy('purpose')
            ->selectRaw('purpose')
            ->selectRaw('sum(calls) as calls')
            ->selectRaw('sum(retry_calls) as retry_calls')
            ->selectRaw('sum(errors) as errors')
            ->selectRaw('sum(cost_micro_usd) as cost_micro_usd')
            ->selectRaw("string_agg(distinct model, ', ') as models")
            ->orderByDesc(DB::raw('sum(cost_micro_usd)'))
            ->get()
            ->map(fn ($r) => [
                'purpose' => (string) $r->purpose,
                'calls' => (int) $r->calls,
                'retry_calls' => (int) $r->retry_calls,
                'errors' => (int) $r->errors,
                'cost_usd' => round(((int) $r->cost_micro_usd) / 1_000_000, 2),
                'models' => (string) $r->models,
            ])
            ->all();
    }
}
