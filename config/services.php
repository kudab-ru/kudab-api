<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'bot' => [
        'shared_token' => env('BOT_SHARED_TOKEN'),

        // Адрес бота внутри сети compose. До сих пор связь была
        // односторонней — бот ходил в API, обратно никто не звал, поэтому
        // переменной не существовало. Привязка канала требует спросить
        // Telegram, а токен есть только у бота.
        'url' => env('KUDAB_BOT_URL', 'http://kudab-bot:8000'),
        'timeout' => (int) env('KUDAB_BOT_TIMEOUT', 10),

        // Telegram-id супер-админа (= ADMIN_CHAT_ID бота). При первой проверке прав
        // BotRoleService само-провижит его в БД, если записи нет (bootstrap из env).
        'superadmin_telegram_id' => (int) env('BOT_SUPERADMIN_TELEGRAM_ID', 0),

        // P0.5 approve-in-DM: ревью-гейт автопостинга. Default OFF — автонаполнение
        // кладёт pending (как сейчас); ON — pending_review + превью владельцу в ЛС +
        // авто-пост по таймауту. Включать на проде после деплоя бота с веткой по type.
        'broadcast_review_gate' => (bool) env('BROADCAST_REVIEW_GATE_ENABLED', false),

        /*
         * Адрес сайта ДЛЯ ССЫЛОК В АДМИНКЕ. Отдельно от APP_URL: тот стоит
         * https://kudab.ru и в разработке тоже, потому что попадает в текст
         * постов — подписчик обязан видеть боевой домен. А ссылка «карточка
         * события» нужна админу здесь и сейчас, и на стенде она должна вести
         * на стенд. Пусто = APP_URL, то есть прежнее поведение.
         */
        'admin_site_url' => env('KUDAB_ADMIN_SITE_URL', ''),
        'broadcast_review_timeout_minutes' => (int) env('BROADCAST_REVIEW_TIMEOUT_MINUTES', 120),

        // Придержка айтема после постановки в очередь: за это окно парсер успевает
        // написать ТГ-текст дорогой моделью (parser:tg:describe-due, everyMinute).
        // Бот физически не может забрать айтем раньше — findActiveForBroadcast не
        // отдаёт pending/planned с будущим planned_at. Если парсер лёг, придержка
        // истекает сама и пост уходит со старым description: деградация пассивная.
        'broadcast_text_grace_minutes' => (int) env('BROADCAST_TEXT_GRACE_MINUTES', 6),
        // За сколько минут до слота парсер пишет анонс посту недельной ленты.
        // ДЕРЖАТЬ РАВНЫМ llm_text.tg_lead_minutes в kudab-parser: там по нему
        // выбирают записи, здесь — показывают человеку время в админке.
        'broadcast_text_lead_minutes' => (int) env('BROADCAST_TEXT_LEAD_MINUTES', 60),
    ],

    'vk' => [
        'token' => env('VK_ACCESS_TOKEN'),
        'version' => env('VK_API_VERSION', '5.131'),
    ],

    /*
     * Яндекс.Метрика — единственный прибор отклика, который у канала может
     * быть: просмотры постов Bot API не отдаёт вовсе (это MTProto-метрика).
     * Ключи уже лежат в общем .env инфры, отдельной настройки не нужно.
     */
    'metrika' => [
        'counter' => env('YANDEX_METRIKA_COUNTER'),
        'token' => env('YANDEX_OAUTH_TOKEN'),
    ],
];
