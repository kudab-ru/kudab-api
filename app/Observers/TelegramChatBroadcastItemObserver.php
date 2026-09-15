<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TelegramChatBroadcastItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Держит связь «пост → события» в согласии с колонкой `items.event_id`.
 *
 * ПОЧЕМУ ОБСЕРВЕР, А НЕ ЧЕТЫРЕ ЯВНЫХ ВЫЗОВА. Присваиваний `event_id` в
 * приложении ровно четыре, и их можно было бы закрыть руками. Но записи
 * создаются ещё и в тестах — больше десятка мест конструируют модель напрямую,
 * минуя всех четырёх писателей. Явные вызовы оставили бы эти фикстуры без
 * строк связи, и как только читатели анти-дублей перейдут на неё, проверки
 * начнут молча проходить на пустоте. Один писатель надёжнее четырёх.
 *
 * ЗАПИСЬ БЕЗ СОБЫТИЯ СВОЕЙ СВЯЗЬЮ НЕ УПРАВЛЯЕТ. У портрета площадки строк ноль
 * и это верно; у подборки состав пишет её композитор, и трогать его здесь
 * значило бы стирать состав при каждом сохранении записи.
 *
 * ПОЧЕМУ ПРОВЕРКА НАЛИЧИЯ ТАБЛИЦЫ. `make prod-deploy` поднимает новый код ДО
 * `migrate`: между ними есть окно, в котором обсервер уже жив, а таблицы ещё
 * нет. Без проверки в этом окне падало бы КАЖДОЕ сохранение записи очереди —
 * то есть вся рассылка. Та же защита делает безопасным откат на предыдущий
 * пин. Недостачу строк после такого окна показывает
 * `broadcast:links:backfill --check`, а чинит он же.
 */
final class TelegramChatBroadcastItemObserver
{
    public const TABLE = 'telegram.chat_broadcast_item_events';

    private const WARN_THROTTLE_SECONDS = 300;

    private static ?bool $tableExists = null;

    public function saved(TelegramChatBroadcastItem $item): void
    {
        if ($item->event_id === null) {
            return;
        }

        try {
            if (! self::tableAvailable()) {
                return;
            }

            // Ведущее событие записи могли сменить (оживление под другое
            // событие) — старую строку убираем, иначе у поста окажется два
            // ведущих, чего не даст частичный уникальный индекс.
            DB::table(self::TABLE)
                ->where('item_id', $item->id)
                ->where('position', 0)
                ->where('event_id', '<>', $item->event_id)
                ->delete();

            DB::table(self::TABLE)->insertOrIgnore([
                'item_id' => $item->id,
                'event_id' => $item->event_id,
                'position' => 0,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Связь пока никем не читается; ронять из-за неё постановку в
            // очередь нельзя. Когда читатели перейдут на неё, эта ветка
            // останется страховкой, а недостачу покажет --check.
            self::warnThrottled($e);
        }
    }

    /** Только для тестов: забыть, есть ли таблица. */
    public static function forgetTableCache(): void
    {
        self::$tableExists = null;
    }

    private static function tableAvailable(): bool
    {
        return self::$tableExists ??= Schema::hasTable(self::TABLE);
    }

    private static function warnThrottled(Throwable $e): void
    {
        try {
            if (\Illuminate\Support\Facades\Cache::add('broadcast-links:warn-lock', 1, self::WARN_THROTTLE_SECONDS)) {
                Log::warning('broadcast.links.write_failed', ['err' => mb_substr($e->getMessage(), 0, 300)]);
            }
        } catch (Throwable) {
            // кэш тоже лёг — связь не тот повод, чтобы падать
        }
    }
}
