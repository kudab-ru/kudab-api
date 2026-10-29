<?php

return [
    // Пока вход не готов, на проде выключено: все маршруты api/kulisy отвечают 404.
    'enabled' => (bool) env('KULISY_ENABLED', false),

    // Куда вести человека из письма: страница кулис на сайте.
    'site_url' => env('KULISY_SITE_URL', 'https://kulisy.kudab.ru'),

    // Сколько живёт код входа через бота.
    'telegram_code_ttl' => 600,

    // Сколько помнить вход на одном устройстве.
    'token_days' => 30,
];
