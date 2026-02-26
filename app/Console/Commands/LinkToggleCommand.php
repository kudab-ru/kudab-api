<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkToggleCommand extends Command
{
    /**
     * Примеры:
     *  php artisan link:toggle 123 --set=black
     *  php artisan link:toggle 123 --set=gray
     *  php artisan link:toggle 123 --set=active
     *  php artisan link:toggle 123              # toggle: active <-> black
     */
    protected $signature = 'link:toggle
                            {link : ID community_social_links.id}
                            {--set= : active|gray|black}';

    protected $description = 'Toggle/set status for community_social_links (active|gray|black)';

    public function handle(): int
    {
        $id = (int) $this->argument('link');
        if ($id <= 0) {
            $this->error('Invalid link id.');
            return self::FAILURE;
        }

        $row = DB::table('community_social_links')
            ->select(['id', 'community_id', 'social_network_id', 'status', 'url', 'external_community_id'])
            ->where('id', $id)
            ->first();

        if (!$row) {
            $this->error("Link not found: id={$id}");
            return self::FAILURE;
        }

        $current = strtolower((string)($row->status ?? 'active'));
        if (!in_array($current, ['active', 'gray', 'black'], true)) {
            $current = 'active';
        }

        $set = $this->option('set');
        $target = null;

        if ($set !== null && $set !== '') {
            $set = strtolower(trim((string)$set));
            if (!in_array($set, ['active', 'gray', 'black'], true)) {
                $this->error("Invalid --set value: {$set}. Allowed: active|gray|black");
                return self::FAILURE;
            }
            $target = $set;
        } else {
            // default toggle: active/gray -> black, black -> active
            $target = ($current === 'black') ? 'active' : 'black';
        }

        if ($target === $current) {
            $this->info("Link #{$id}: status already {$current}");
            return self::SUCCESS;
        }

        DB::table('community_social_links')
            ->where('id', $id)
            ->update([
                'status' => $target,
                'updated_at' => now(),
            ]);

        $this->info(sprintf(
            "Link #%d: %s -> %s | community_id=%s network_id=%s | %s",
            $id,
            $current,
            $target,
            (string)$row->community_id,
            (string)$row->social_network_id,
            (string)$row->url
        ));

        return self::SUCCESS;
    }
}
