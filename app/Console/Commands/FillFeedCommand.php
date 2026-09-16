<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Services\Telegram\TelegramChatBroadcastService;
use App\Support\BroadcastSafety;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Заполнить свободные слоты ленты — по расписанию, а не по кнопке.
 *
 * ЗАЧЕМ. Наполнитель ленты (`fillFeedDays`) до сих пор звали ТОЛЬКО две кнопки
 * админки — «Пересобрать неделю» и «Разбавить». В расписании его не было вовсе,
 * а единственный автомат (`broadcast:enqueue-due`) дня записи не назначает и
 * вдобавок выключается собственным капом: он выходит по
 * `открытых событийных >= feed_limit`, а собранная лента этот кап держит всегда.
 * То есть событие, объявленное в среду, в уже собранную неделю попасть НЕ МОГЛО
 * ни при каких условиях — пока человек не нажмёт кнопку.
 *
 * Замер 2026-09-15 по каналу Воронежа: из 154 событий ближайшей недели в ленте
 * 10, ещё 17 сняты анти-дублем, а остальные 127 отбракованы единственной
 * причиной — все слоты заняты.
 *
 * ЧТО ДЕЛАЕТ. Занятый слот не трогает (это правило `fillFeedDays`, и менять его
 * нельзя: лента, которая сама себя переставляет, лишает владельца уверенности,
 * что увиденное утром выйдет вечером). Заполняет только свободные — те, что
 * освободились после отправки, и те, до которых у канала дошёл черёд по
 * настройке `fill_lead_days`.
 *
 * ЧАСОВОЙ ТИК, а не суточный: поздний слот становится «можно заполнять» ровно
 * за `fill_lead_days` суток до себя, и часовой прогон занимает его на этой
 * границе. Суточный прогон либо опережал бы границу, либо проспал бы день.
 *
 * ЗАПРЕТ СТЕНДА действует здесь так же, как в `enqueueDueForAllChannels`: на
 * стенде очередь боевого канала не наполняем вовсе, иначе она копит посты,
 * которые никогда не уйдут, а «одно событие в полёте» блокирует канал.
 */
class FillFeedCommand extends Command
{
    protected $signature = 'broadcast:fill-feed
        {--broadcast= : Только один канал, по id}
        {--dry-run : Показать сводку и ничего не писать}';

    protected $description = 'Заполнить свободные слоты ленты каналов (то же, что кнопка «Пересобрать», но только свободное)';

    public function handle(TelegramChatBroadcastService $service): int
    {
        $now = Carbon::now();
        $dryRun = (bool) $this->option('dry-run');

        $query = TelegramChatBroadcast::query()->with('chat');
        if ($this->option('broadcast') !== null) {
            $query->where('id', (int) $this->option('broadcast'));
        }

        $summary = ['checked' => 0, 'filled' => 0, 'channels' => 0, 'not_allowed' => 0, 'off' => 0];

        foreach ($query->get() as $broadcast) {
            $summary['checked']++;

            if (! $broadcast->enabled || $broadcast->period === 'off') {
                $summary['off']++;

                continue;
            }

            $chat = $broadcast->chat;
            if (! $chat instanceof TelegramChat || ! $chat->city_id || ! $chat->telegram_chat_id) {
                continue;
            }

            if (! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
                $summary['not_allowed']++;

                continue;
            }

            if ($dryRun) {
                continue;
            }

            $filled = $service->fillFeedDays($broadcast, $now);

            if (($filled['filled'] ?? 0) > 0) {
                $summary['channels']++;
                $summary['filled'] += (int) $filled['filled'];

                Log::info('broadcast.feed.filled', [
                    'broadcast_id' => $broadcast->id,
                    'filled' => $filled['filled'],
                    'no_candidate' => $filled['no_candidate'] ?? 0,
                ]);
            }
        }

        $this->info(sprintf(
            'fill-feed: checked=%d filled=%d в %d каналах, off=%d, стенд не пустил=%d%s',
            $summary['checked'], $summary['filled'], $summary['channels'],
            $summary['off'], $summary['not_allowed'],
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        return self::SUCCESS;
    }
}
