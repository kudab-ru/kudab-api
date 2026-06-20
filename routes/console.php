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
