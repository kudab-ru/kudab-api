<?php

return [
    // Сколько дней учитываем при оценке активности (индикативно для источников/LLM)
    'activity_window_days' => env('VERIFICATION_ACTIVITY_WINDOW_DAYS', 60),

    // Порог уверенности HQ, начиная с которого берём адрес как надёжный
    'hq_threshold' => (float) env('VERIFICATION_HQ_THRESHOLD', 0.7),

    // Приоритет источников для выбора HQ (если несколько)
    'hq_priority' => array_map('trim', explode(',', env('VERIFICATION_HQ_PRIORITY', 'site,vk,telegram,tg'))),

    // Лимит постов на источник
    'limit_per_source' => (int) env('VERIFICATION_LIMIT_PER_SOURCE', 20),

    // Версия промпта (пишем в verifications)
    'prompt_version' => (int) env('VERIFICATION_PROMPT_VERSION', 1),
];
