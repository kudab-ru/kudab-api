<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramChatBroadcastService;
use Illuminate\Console\Command;

/**
 * P0.5 approve-in-DM: авто-одобрение просроченных ревью.
 *
 * pending_review с истёкшим review_deadline_at → auto_approved. После этого item
 * подхватывается poll'ом как publish-задача и постится без живого ревью (таймаут =
 * «нет ответа → публикуем»). Гоняется из scheduler каждую минуту.
 */
class BroadcastApproveTimeoutsCommand extends Command
{
    protected $signature = 'broadcast:approve-timeouts';

    protected $description = 'Авто-одобрение просроченных pending_review (approve-in-DM timeout)';

    public function handle(TelegramChatBroadcastService $service): int
    {
        $count = $service->autoApproveExpiredReviews(now());

        $this->info("broadcast:approve-timeouts — auto_approved={$count}");

        return self::SUCCESS;
    }
}
