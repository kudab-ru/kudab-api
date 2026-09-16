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

// Переходы по ссылкам постов — из Метрики, раз в сутки. Чаще незачем: визит
// может случиться и через неделю после поста, и Метрика доуточняет данные
// задним числом, поэтому окно всё равно перечитывается целиком.
Schedule::command('broadcast:collect-clicks')
    ->dailyAt('05:20')
    ->onOneServer()
    ->withoutOverlapping(30);

// Уборка очереди ожидания — за десять минут до наполнителя, чтобы освобождённые
// ячейки feed_limit достались тому же прогону. Запись без дня ждёт свободного
// слота, и её просроченность до сих пор замечала только доставка — а до головы
// очереди запись с заполненной лентой не добирается никогда.
Schedule::command('broadcast:sweep-queue')
    ->hourlyAt(50)
    ->onOneServer()
    ->withoutOverlapping(10);

// Заполнение свободных слотов ленты. До этого наполнитель звали только две
// кнопки админки, а автомат enqueue-due дня не назначает и выключен собственным
// капом на любой собранной ленте — то есть событие, объявленное в среду, в
// неделю попасть не могло вовсе. Часовой тик, потому что поздний слот
// становится доступным ровно за fill_lead_days суток до себя и занимать его
// надо на этой границе, а не в произвольный час суток.
Schedule::command('broadcast:fill-feed')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10);

// Бронь слота под подборку недели. Рядом с портретами и по той же причине:
// слот держит только существующая запись, иначе вечер понедельника разберут
// под обычные посты. Запись встаёт ПУСТОЙ — состав и текст ей соберут перед
// самой отправкой. Раз в час: чаще незачем, реже — риск проспать слот, если
// прошлая подборка ушла только что.
Schedule::command('broadcast:enqueue-digests')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10);
