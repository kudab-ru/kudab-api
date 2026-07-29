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

        // Telegram-id супер-админа (= ADMIN_CHAT_ID бота). При первой проверке прав
        // BotRoleService само-провижит его в БД, если записи нет (bootstrap из env).
        'superadmin_telegram_id' => (int) env('BOT_SUPERADMIN_TELEGRAM_ID', 0),

        // P0.5 approve-in-DM: ревью-гейт автопостинга. Default OFF — автонаполнение
        // кладёт pending (как сейчас); ON — pending_review + превью владельцу в ЛС +
        // авто-пост по таймауту. Включать на проде после деплоя бота с веткой по type.
        'broadcast_review_gate' => (bool) env('BROADCAST_REVIEW_GATE_ENABLED', false),
        'broadcast_review_timeout_minutes' => (int) env('BROADCAST_REVIEW_TIMEOUT_MINUTES', 120),

        // Придержка айтема после постановки в очередь: за это окно парсер успевает
        // написать ТГ-текст дорогой моделью (parser:tg:describe-due, everyMinute).
        // Бот физически не может забрать айтем раньше — findActiveForBroadcast не
        // отдаёт pending/planned с будущим planned_at. Если парсер лёг, придержка
        // истекает сама и пост уходит со старым description: деградация пассивная.
        'broadcast_text_grace_minutes' => (int) env('BROADCAST_TEXT_GRACE_MINUTES', 6),
    ],

    'vk' => [
        'token'   => env('VK_ACCESS_TOKEN'),
        'version' => env('VK_API_VERSION', '5.131'),
    ],
];
