<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Telegram\TelegramChatBroadcastService;
use App\Support\BroadcastSafety;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Собрать подборку недели ЗАРАНЕЕ — за сутки до слота, а не в минуту отправки.
 *
 * ЗАЧЕМ так рано и почему именно столько часов — замеры и разбор лежат у самой
 * настройки, `config/broadcast_digest.prepare_hours`.
 *
 * ЧТО ЗАМОРАЖИВАЕТСЯ. Только состав: заранее решается «про что», а не «какими
 * словами». Подпись пересобирает доставка — диапазон дат, цены и время обязаны
 * быть свежими на момент выхода.
 *
 * ЗАПРЕТ СТЕНДА действует на живом прогоне: сборка безобидна, но заявка на
 * текст стоит денег за модель, а пост из запрещённого канала не уйдёт.
 */
class PrepareDigestsCommand extends Command
{
    protected $signature = 'broadcast:prepare-digests
        {--hours= : Горизонт в часах вместо настройки}
        {--item= : Только одна запись, по id}
        {--dry-run : Показать, что собрал бы, и ничего не писать}';

    protected $description = 'Собрать состав подборок недели заранее и заказать им текст';

    public function handle(TelegramChatBroadcastService $service): int
    {
        $now = Carbon::now();
        $dryRun = (bool) $this->option('dry-run');
        $hours = $this->option('hours') !== null
            ? max(1, (int) $this->option('hours'))
            : (int) config('broadcast_digest.prepare_hours', 18);

        $query = TelegramChatBroadcastItem::query()
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->whereNull('posted_at')
            // Только живые статусы: снятая и отклонённая бронь уйти не может, а
            // заявку на текст, выставленную ей, потом не снимает никто —
            // застрявшая заявка пережила бы и возврат записи.
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->whereNotNull('publish_at')
            ->where('publish_at', '>', $now)
            ->where('publish_at', '<=', $now->copy()->addHours($hours))
            // Заявка в полёте — ждём парсер, а не шлём вторую.
            ->whereNull('text_requested_at')
            ->orderBy('publish_at');

        if ($this->option('item') !== null) {
            $query->where('id', (int) $this->option('item'));
        }

        $items = $query->get();
        $summary = ['checked' => 0, 'composed' => 0, 'text_requested' => 0, 'ready' => 0, 'failed' => 0, 'not_allowed' => 0];

        foreach ($items as $item) {
            $summary['checked']++;

            $broadcast = TelegramChatBroadcast::query()->with('chat.city')->find($item->broadcast_id);
            $chat = $broadcast?->chat;

            if (! $broadcast || ! $chat instanceof TelegramChat || ! $chat->city_id) {
                $summary['failed']++;
                $this->line(sprintf('  запись #%d: у канала нет города — пропускаю', $item->id));

                continue;
            }

            $allowed = BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id);

            // Сухой прогон показывает ВСЁ, включая запрещённые стенду каналы:
            // он ничего не пишет и не платит, а прятать от него половину
            // картины значит отвечать не на тот вопрос, который задали.
            if ($dryRun) {
                $this->line(sprintf(
                    '  запись #%d: слот %s, собрал бы %s%s',
                    $item->id,
                    Carbon::parse($item->publish_at)->toDateTimeString(),
                    $item->digestTheme() === null ? 'состав и текст' : 'только текст',
                    $allowed ? '' : ' (на живом прогоне пропустил бы: стенду сюда нельзя)',
                ));

                continue;
            }

            if (! $allowed) {
                $summary['not_allowed']++;
                $this->line(sprintf(
                    '  запись #%d: стенду постить в этот канал нельзя — не готовлю (иначе платим за текст впустую)',
                    $item->id,
                ));

                continue;
            }

            $result = $service->prepareDigestAhead($item, $broadcast, $now);
            $summary[$result] = ($summary[$result] ?? 0) + 1;

            $this->line(sprintf(
                '  запись #%d: слот %s → %s',
                $item->id,
                Carbon::parse($item->publish_at)->toDateTimeString(),
                $result,
            ));

            if ($result !== 'failed') {
                Log::info('broadcast.digest.prepared_ahead', [
                    'item_id' => $item->id,
                    'broadcast_id' => $broadcast->id,
                    'publish_at' => Carbon::parse($item->publish_at)->toIso8601String(),
                    'hours_ahead' => round(Carbon::parse($item->publish_at)->floatDiffInHours($now), 1),
                    'result' => $result,
                ]);
            }
        }

        $this->info(sprintf(
            'prepare-digests: горизонт %d ч, записей %d, собрано %d, заказан текст %d, уже готово %d, не вышло %d, стенд не пустил %d%s',
            $hours,
            $summary['checked'],
            $summary['composed'],
            $summary['text_requested'],
            $summary['ready'],
            $summary['failed'],
            $summary['not_allowed'],
            $dryRun ? ' [DRY-RUN, ничего не записано]' : '',
        ));

        return self::SUCCESS;
    }
}
