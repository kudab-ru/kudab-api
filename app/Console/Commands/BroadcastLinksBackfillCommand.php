<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Observers\TelegramChatBroadcastItemObserver as Links;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Заполнить связь «пост → события» по колонке `items.event_id`.
 *
 * ПОЧЕМУ ОТДЕЛЬНОЙ КОМАНДОЙ, А НЕ В МИГРАЦИИ. Миграция выполняется ровно один
 * раз: Laravel пишет строку в `migrations` и второй раз `up()` не зовёт.
 * Значит после отката на предыдущий пин и повторного выката заполнить связь
 * было бы нечем, а недостачу никто бы не заметил — читатели анти-дублей молча
 * перестали бы видеть занятые события. Команда идемпотентна и запускается
 * сколько угодно раз.
 *
 * `--check` печатает недостачу и ничем не пишет: это прибор, а не лечение.
 * Его же стоит прогонять после каждого выката, трогавшего очередь.
 */
class BroadcastLinksBackfillCommand extends Command
{
    protected $signature = 'broadcast:links:backfill
        {--check : Только показать недостачу, ничего не писать}';

    protected $description = 'Заполнить связь «пост → события» по items.event_id (идемпотентно)';

    public function handle(): int
    {
        $missing = $this->missingCount();
        $extra = $this->orphanCount();

        $this->line(sprintf('Записей с событием без строки связи: <comment>%d</comment>', $missing));
        $this->line(sprintf('Строк связи без своей записи или события: <comment>%d</comment>', $extra));

        if ($this->option('check')) {
            // Ненулевая недостача — повод для внимания, а не для падения:
            // команду зовут и руками, и из проверок после выката.
            return $missing === 0 ? self::SUCCESS : self::FAILURE;
        }

        if ($missing === 0) {
            $this->info('Связь полна — писать нечего.');

            return self::SUCCESS;
        }

        // ON CONFLICT DO NOTHING делает повтор безопасным: строки, которые уже
        // положил обсервер, просто не тронутся.
        $written = DB::affectingStatement(
            'INSERT INTO '.Links::TABLE.' (item_id, event_id, position, created_at)
             SELECT i.id, i.event_id, 0, COALESCE(i.created_at, now())
             FROM telegram.chat_broadcast_items i
             WHERE i.event_id IS NOT NULL
             ON CONFLICT (item_id, event_id) DO NOTHING'
        );

        $this->info(sprintf('Дописано строк связи: %d. Осталось недостачи: %d.', $written, $this->missingCount()));

        return self::SUCCESS;
    }

    /** Записи с событием, у которых нет ведущей строки связи. */
    private function missingCount(): int
    {
        return (int) DB::table('telegram.chat_broadcast_items as i')
            ->whereNotNull('i.event_id')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from(Links::TABLE.' as l')
                    ->whereColumn('l.item_id', 'i.id')
                    ->whereColumn('l.event_id', 'i.event_id');
            })
            ->count();
    }

    /**
     * Строки связи, потерявшие хозяина. Внешние ключи стоят с каскадом, так что
     * в норме это ноль; ненулевое значение означает, что кто-то писал в таблицу
     * мимо них — и об этом лучше знать.
     */
    private function orphanCount(): int
    {
        return (int) DB::table(Links::TABLE.' as l')
            ->leftJoin('telegram.chat_broadcast_items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('events as e', 'e.id', '=', 'l.event_id')
            ->whereNull('i.id')
            ->orWhereNull('e.id')
            ->count();
    }
}
