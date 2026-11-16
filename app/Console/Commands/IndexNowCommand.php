<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\EventRepository;
use App\Support\BroadcastSafety;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class IndexNowCommand extends Command
{
    protected $signature = 'seo:indexnow {--pages : добавить разделы, у которых содержимое меняется каждый день}';

    protected $description = 'Сообщить Яндексу и Bing о новых событиях через IndexNow';

    // Праздники и виды событий робот найдёт по карте сайта, здесь только то, что обновляется ежедневно.
    private const PAGES = [
        '/',
        '/afisha/voronezh',
        '/afisha/voronezh/na-vyhodnyh',
        '/afisha/voronezh/segodnya',
        '/afisha/voronezh/besplatno',
        '/afisha/voronezh/katki',
        '/afisha/voronezh/prazdniki',
    ];

    // Номер последнего отправленного события. Номера только растут, поэтому новые — всё, что выше.
    public const CURSOR = 'indexnow:last-event-id';

    // Предел протокола на один запрос.
    private const MAX_URLS = 10000;

    public function handle(EventRepository $events): int
    {
        // На стенде база — копия прода, но номера новых событий свои: в поиск ушли бы чужие адреса.
        if (! BroadcastSafety::unrestricted()) {
            $this->info('не прод: адреса не отправляются');

            return self::SUCCESS;
        }

        $site = rtrim((string) config('services.indexnow.site'), '/');
        $page = $events->listWebIdsForSitemap((int) Cache::get(self::CURSOR, 0), self::MAX_URLS, 'upcoming');
        $ids = array_column($page['items'], 'id');

        $urls = array_map(fn (int $id) => "{$site}/events/{$id}", $ids);
        if ($this->option('pages')) {
            $urls = [...array_map(fn (string $p) => $site.$p, self::PAGES), ...$urls];
        }

        if ($urls === []) {
            $this->info('новых адресов нет');

            return self::SUCCESS;
        }

        $accepted = $this->send($site, array_slice($urls, 0, self::MAX_URLS));

        // Номер двигаем, только если принял Яндекс (первый в списке): иначе эти события он не увидит до карты сайта.
        if ($accepted && $ids !== []) {
            Cache::forever(self::CURSOR, max($ids));
        }

        return $accepted ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<string> $urls */
    private function send(string $site, array $urls): bool
    {
        $key = (string) config('services.indexnow.key');
        $body = [
            'host' => (string) parse_url($site, PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => "{$site}/{$key}.txt",
            'urlList' => $urls,
        ];

        $first = null;
        foreach ((array) config('services.indexnow.endpoints') as $endpoint) {
            try {
                $status = Http::timeout(30)->asJson()->post($endpoint, $body)->status();
            } catch (Throwable $e) {
                $status = 0;
                $this->warn("{$endpoint}: {$e->getMessage()}");
            }
            $this->info("{$endpoint}: {$status}, адресов ".count($urls));
            $first ??= in_array($status, [200, 202], true);
        }

        return (bool) $first;
    }
}
