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
 * ЗАЧЕМ. Состав и текст подборки появлялись внутри доставки, и между «состав
 * зафиксирован» и «пост в канале» проходили секунды. Замер по отправленным:
 * запись 214 — состав записан 16:33:11, пост ушёл 16:33:12; запись 215 —
 * 16:43:35 и 16:43:37. Причина не в придержке (шесть минут из настроек — это
 * ПОТОЛОК ожидания, а не пауза): парсер снимает придержку в ту же секунду,
 * когда текст готов, и следующий тик поллера уже несёт пост в канал.
 *
 * Отсюда следствие, которое и чинит эта команда: посмотреть на состав перед
 * выходом было НЕКОГДА. Ни поменять позицию, ни переписать строку, ни просто
 * убедиться, что рубрика не собрала три спектакля одного театра.
 *
 * ЧТО ЗАМОРАЖИВАЕТСЯ. Только состав. Подпись пересобирается перед отправкой,
 * как и раньше: шапка с диапазоном дат, цены и время обязаны быть свежими на
 * момент выхода. То есть заранее решается «про что», а не «какими словами».
 *
 * ЦЕНА. Подборка, собранная за сутки, не знает событий, которые объявят
 * завтра утром. Это осознанный размен, и он маленький: в подборку попадает
 * только то, что прошло фильтры пула, а поздние анонсы в них почти не
 * проходят. Горизонт — `broadcast_digest.prepare_hours`, там же объяснение,
 * почему именно столько.
 *
 * ЧАСОВОЙ ТИК: граница «за N часов до слота» наступает в произвольную минуту,
 * и суточный прогон либо опережал бы её на полсуток, либо проспал.
 *
 * ЗАПРЕТ СТЕНДА действует: канал, которому стенду постить нельзя, не готовим
 * вовсе. Сборка сама по себе безобидна, но заявка на текст стоит денег за
 * модель, а пост всё равно никогда не уйдёт.
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

            if (! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
                $summary['not_allowed']++;
                $this->line(sprintf(
                    '  запись #%d: стенду постить в этот канал нельзя — не готовлю (иначе платим за текст впустую)',
                    $item->id,
                ));

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '  запись #%d: слот %s, собрал бы %s',
                    $item->id,
                    Carbon::parse($item->publish_at)->toDateTimeString(),
                    $item->digestTheme() === null ? 'состав и текст' : 'только текст',
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
