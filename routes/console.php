<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// P0 автопостинг (фаза 1): автонаполнение очереди city-каналов. Сам постинг —
// существующий bot-cron (poll → send → mark-sent). schedule:run гоняет контейнер
// kudab-api-scheduler каждые 60с. Расписание поста — per-channel в chat_broadcasts.settings.
Schedule::command('broadcast:enqueue-due')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// P0.5 approve-in-DM: авто-пост просроченных pending_review по таймауту.
Schedule::command('broadcast:approve-timeouts')
    ->everyMinute()
    ->withoutOverlapping();

// Этап 2 venue-portrait: наполнение очереди портретами площадок. Каденс (≈раз в
// неделю на канал) + ротация без повторов — внутри команды; часовой тик ловит
// свободное окно очереди. Доставка — тот же bot-cron, что у событий.
Schedule::command('broadcast:enqueue-venue-portraits')
    ->hourly()
    ->withoutOverlapping();
