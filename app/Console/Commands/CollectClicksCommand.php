<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сколько человек пришло на сайт с каждого поста.
 *
 * ЕДИНСТВЕННЫЙ ПРИБОР, КОТОРЫЙ У КАНАЛА ВОЗМОЖЕН. Просмотры постов Bot API не
 * отдаёт — это метрика MTProto, боту недоступная; реакции доступны только на
 * каналах, где они включены, и только начиная с Bot API 7.0. А переходы видит
 * Метрика, и видит поимённо: ссылка в посте несёт метку `utm_content = i<номер
 * записи>` (см. [[EventCaptionBuilder]]), и по ней визит связывается с
 * КОНКРЕТНЫМ постом, а не с каналом вообще.
 *
 * ПОЧЕМУ ЭТО ВАЖНЕЕ, ЧЕМ КАЖЕТСЯ. До сих пор у канала не было ни одного числа:
 * любой спор о качестве («живо — не живо», «какие события ставить») решался
 * мнением, а веса подбора настраивались вслепую. Даже один правдивый счётчик
 * меняет разговор.
 *
 * ОКНО. Считаем последние `--days` суток и каждый раз перезаписываем: визит
 * может случиться и через неделю после поста, а Метрика доуточняет данные
 * задним числом. Поэтому `clicks` — это «столько было на момент clicks_at», а
 * не «столько всего».
 *
 * NULL против нуля: `clicks = NULL` значит «не мерили», `0` — «мерили,
 * переходов не было». Разница видна в админке и именно её обычно и хотят знать.
 */
class CollectClicksCommand extends Command
{
    protected $signature = 'broadcast:collect-clicks
        {--days=30 : За сколько суток спрашивать Метрику}
        {--dry-run : Показать, что насчитали, и ничего не писать}';

    protected $description = 'Снять из Метрики переходы по ссылкам постов канала (метка utm_content)';

    private const ENDPOINT = 'https://api-metrika.yandex.net/stat/v1/data';

    public function handle(): int
    {
        $counter = trim((string) config('services.metrika.counter'));
        $token = trim((string) config('services.metrika.token'));

        if ($counter === '' || $token === '') {
            $this->warn('Метрика не настроена: нет YANDEX_METRIKA_COUNTER или YANDEX_OAUTH_TOKEN.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Authorization' => 'OAuth '.$token])
                ->get(self::ENDPOINT, [
                    'ids' => $counter,
                    'metrics' => 'ym:s:visits',
                    'dimensions' => 'ym:s:UTMContent',
                    // Только наш канал: метку utm_content может ставить кто
                    // угодно, а считать мы хотим ровно свои посты.
                    'filters' => "ym:s:UTMSource=='tg'",
                    'date1' => $days.'daysAgo',
                    'date2' => 'today',
                    'limit' => 10000,
                    'accuracy' => 'full',
                ]);
        } catch (\Throwable $e) {
            $this->error('Метрика недоступна: '.mb_substr($e->getMessage(), 0, 200));

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Метрика ответила '.$response->status().': '.mb_substr((string) $response->body(), 0, 200));

            return self::FAILURE;
        }

        $rows = (array) ($response->json('data') ?? []);
        $byItem = [];

        foreach ($rows as $row) {
            $label = (string) ($row['dimensions'][0]['name'] ?? '');
            $visits = (int) ($row['metrics'][0] ?? 0);

            // Метка вида «i123»: буква не украшение, она отличает наш номер от
            // чужой метки, случайно совпавшей числом.
            if (! preg_match('/^i(\d+)$/', $label, $m)) {
                continue;
            }

            $byItem[(int) $m[1]] = ($byItem[(int) $m[1]] ?? 0) + $visits;
        }

        if ($byItem === []) {
            $this->info('collect-clicks: переходов с метками канала за '.$days.' суток нет.');
        }

        // Посты окна, у которых меток не нашлось, тоже надо закрыть нулём:
        // иначе «не мерили» и «переходов не было» навсегда остаются
        // неразличимы, а второе — это и есть ответ, ради которого мерили.
        $posted = TelegramChatBroadcastItem::query()
            ->whereNotNull('posted_at')
            ->where('posted_at', '>=', Carbon::now()->subDays($days))
            ->get(['id', 'clicks']);

        $written = 0;
        foreach ($posted as $item) {
            $clicks = $byItem[$item->id] ?? 0;

            if ((int) $item->clicks === $clicks && $item->clicks !== null) {
                continue;
            }

            $this->line(sprintf('  #%d — %d переход(ов)', $item->id, $clicks));

            if (! $this->option('dry-run')) {
                TelegramChatBroadcastItem::query()->whereKey($item->id)->update([
                    'clicks' => $clicks,
                    'clicks_at' => Carbon::now(),
                ]);
            }
            $written++;
        }

        $this->info(sprintf(
            'collect-clicks: постов в окне %d, с переходами %d, обновлено %d%s',
            $posted->count(),
            count($byItem),
            $written,
            $this->option('dry-run') ? ' [DRY-RUN]' : '',
        ));

        Log::info('broadcast.clicks.collected', [
            'days' => $days,
            'posts' => $posted->count(),
            'with_clicks' => count($byItem),
        ]);

        return self::SUCCESS;
    }
}
