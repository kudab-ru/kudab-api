<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Показать и сменить активную модель текст-движка (таблица llm_text_configs).
 *
 * Пока у настройки нет страницы в админке, это единственный способ флипнуть модель
 * без деплоя: парсер перечитывает строку сам, со следующего прогона ночных команд.
 *
 * Whitelist моделей живёт в парсере (config/llm_text.php) — здесь список задан
 * зеркалом только для подсказки в выводе; незнакомое значение резолвер парсера
 * погасит откатом на дефолт, а не остановкой генерации.
 */
class LlmTextModelCommand extends Command
{
    protected $signature = 'llm:text:model
        {purpose? : bulk (описания каталога) или tg (тексты в канал)}
        {model? : id модели; без него — только показать текущее}';

    protected $description = 'Показать или задать активную модель текст-движка (bulk / tg)';

    /** Зеркало whitelist парсера — для подсказки в выводе. Исполнительная правда там. */
    private const KNOWN_MODELS = [
        'claude-sonnet-4-6',
        'claude-haiku-4-5',
        'claude-opus-4-6',
        'claude-sonnet-5',
        'claude-opus-4-7',
        'claude-opus-4-8',
        'claude-opus-5',
        'gpt-4o-mini',
        'gpt-4.1',
    ];

    private const PURPOSES = ['bulk', 'tg'];

    public function handle(): int
    {
        if (! Schema::hasTable('llm_text_configs')) {
            $this->error('Таблица llm_text_configs не найдена — накати миграции.');

            return self::FAILURE;
        }

        $purpose = $this->argument('purpose');
        $model = $this->argument('model');

        if ($purpose === null) {
            return $this->show();
        }

        if (! in_array($purpose, self::PURPOSES, true)) {
            $this->error(sprintf('Назначение «%s» неизвестно. Доступны: %s.', $purpose, implode(', ', self::PURPOSES)));

            return self::FAILURE;
        }

        if ($model === null) {
            return $this->show();
        }

        if ($model === 'default') {
            $map = $this->currentMap();
            unset($map[$purpose]);
            DB::table('llm_text_configs')->updateOrInsert(
                ['key' => 'default'],
                ['models' => json_encode($map, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
            );
            $this->info(sprintf('Назначение %s сброшено на дефолт парсера.', $purpose));

            return self::SUCCESS;
        }

        if (! in_array($model, self::KNOWN_MODELS, true)) {
            $this->error(sprintf('Модель «%s» не в whitelist. Доступны: %s.', $model, implode(', ', self::KNOWN_MODELS)));

            return self::FAILURE;
        }

        $map = $this->currentMap();
        $was = $map[$purpose] ?? null;
        $map[$purpose] = $model;

        DB::table('llm_text_configs')->updateOrInsert(
            ['key' => 'default'],
            ['models' => json_encode($map, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );

        $this->info(sprintf(
            'Назначение %s: %s → %s. Применится в течение 30 секунд, деплой не нужен.',
            $purpose,
            $was ?? 'дефолт из config',
            $model,
        ));

        return self::SUCCESS;
    }

    private function show(): int
    {
        $map = $this->currentMap();

        $this->table(
            ['Назначение', 'Модель', 'Источник'],
            array_map(
                fn (string $p) => [$p, $map[$p] ?? '—', isset($map[$p]) ? 'БД' : 'дефолт парсера (config/llm_text.php)'],
                self::PURPOSES,
            ),
        );

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function currentMap(): array
    {
        $raw = DB::table('llm_text_configs')->where('key', 'default')->value('models');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : [];
    }
}
