<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Модели текст-движка — зеркало для админки
|--------------------------------------------------------------------------
|
| ИСПОЛНИТЕЛЬНАЯ правда живёт в парсере: services/kudab-parser/config/llm_text.php
| (там же temperature, потолки вывода и пороги кэша — их знает только тот, кто зовёт
| провайдера). Здесь — только то, что нужно показать человеку в форме и проверить
| на входе: id, подпись и цена.
|
| ДЕРЖАТЬ В СИНХРОНЕ с парсерным config/llm_text.php при добавлении модели.
| Рассинхрон не опасен: незнакомую модель резолвер парсера гасит откатом на дефолт
| и пишет warning, а не останавливает ночную генерацию.
*/

return [

    'claude-sonnet-4-6' => ['label' => 'Claude Sonnet 4.6', 'price_in' => 3.0, 'price_out' => 15.0],
    'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5', 'price_in' => 1.0, 'price_out' => 5.0],
    'claude-opus-4-6' => ['label' => 'Claude Opus 4.6', 'price_in' => 5.0, 'price_out' => 25.0],
    'claude-sonnet-5' => ['label' => 'Claude Sonnet 5', 'price_in' => 3.0, 'price_out' => 15.0],
    'claude-opus-4-7' => ['label' => 'Claude Opus 4.7', 'price_in' => 5.0, 'price_out' => 25.0],
    'claude-opus-4-8' => ['label' => 'Claude Opus 4.8', 'price_in' => 5.0, 'price_out' => 25.0],
    'claude-opus-5' => ['label' => 'Claude Opus 5', 'price_in' => 5.0, 'price_out' => 25.0],
    'gpt-4o-mini' => ['label' => 'GPT-4o mini', 'price_in' => null, 'price_out' => null],
    'gpt-4.1' => ['label' => 'GPT-4.1', 'price_in' => null, 'price_out' => null],

];
