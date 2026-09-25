<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\AnalyticsWarmCommand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Страница «Аналитика: рост».
 *
 * Сторожим четыре свойства, каждое из которых уже успело соврать на живых
 * данных:
 *  - фильтр служебного трафика уходит В КАЖДЫЙ запрос к Метрике. Наш
 *    headless-браузер дал 296 визитов из 1320 за месяц, 201 из них за один
 *    день; забытый фильтр превращает отчёт о росте в отчёт о нашей же работе;
 *  - незакрытая неделя не отдаётся вовсе — оборванная на середине, рядом с
 *    целыми она читается как провал;
 *  - молчащий Вебмастер роняет свою секцию в null и пишет строку в meta.errors,
 *    но не роняет ни ответ, ни цифры из нашей базы;
 *  - визит с /events/{id} доходит до источника события, а не теряется по
 *    дороге через event_sources.
 */
class AnalyticsGrowthTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/analytics/growth';

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $this->seedFutureEvent();
    }

    /**
     * Ручка отдаёт только готовый кэш, а наполняет его `analytics:warm`.
     * В тестах делаем ровно это же: сперва прогрев, потом запрос.
     */
    private function warmAndGet(): \Illuminate\Testing\TestResponse
    {
        Artisan::call('analytics:warm');

        return $this->getJson(self::URL)->assertOk();
    }

    public function test_отдаёт_ответ_по_контракту(): void
    {
        $this->fakeAll();

        $response = $this->warmAndGet();

        $response->assertJsonStructure([
            'data' => [
                'generated_at', 'period_days',
                'verdict' => ['state', 'title', 'note', 'blocks' => [['from', 'to', 'visits']]],
                'search' => [
                    'visits', 'users', 'prev_visits', 'delta_pct',
                    'weeks' => [['from', 'to', 'visits', 'provisional']],
                    'noise_pct', 'min_detectable_per_month',
                ],
                'chain' => [
                    'cards_ahead', 'cards_horizon', 'cards_expiring_7d', 'cards_in_feed',
                    'index_pages', 'index_prev', 'index_delta', 'index_date', 'index_prev_date',
                    'landing_pages',
                ],
                'sources' => [
                    'rows' => [['key', 'label', 'visits', 'weeks']],
                    'total',
                    'filtered_headless' => ['visits', 'last_spike_date', 'last_spike_visits'],
                ],
                'landing' => ['rows' => [['key', 'label', 'visits']], 'distribution' => [['bucket', 'pages']], 'median', 'top10_share_pct'],
                'card_sources',
                'goals' => [['id', 'label', 'visits']],
                'blind' => ['google_visits', 'gsc_connected', 'webmaster_shows', 'webmaster_clicks', 'webmaster_lag_days'],
            ],
            'meta' => ['cached_until', 'stale', 'errors'],
        ]);

        $data = $response->json('data');

        $this->assertSame(30, $data['period_days']);
        $this->assertSame(851, $data['search']['visits']);
        $this->assertSame(558, $data['search']['prev_visits']);
        $this->assertSame(53, $data['search']['delta_pct']);
        $this->assertSame(100, $data['search']['min_detectable_per_month']);
        $this->assertSame('growing', $data['verdict']['state']);
        $this->assertCount(3, $data['verdict']['blocks'], 'двенадцать недель дают ровно три месячных блока');
        $this->assertSame(198, $data['verdict']['blocks'][0]['visits']);

        $this->assertSame(1155, $data['chain']['index_pages']);
        $this->assertSame(589, $data['chain']['index_delta']);
        // Ровно то, что задано подделкой выше: залп плюс фон по 2 в день.
        $this->assertSame(201 + 29 * 2, $data['sources']['filtered_headless']['visits']);
        $this->assertSame(201, $data['sources']['filtered_headless']['last_spike_visits']);

        // Подписи столбцов — всегда строки: ключ массива «1» PHP успевает
        // превратить в целое, и фронт получал разнотипные значения.
        foreach ($data['landing']['distribution'] as $bucket) {
            $this->assertIsString($bucket['bucket']);
        }

        $this->assertSame(
            [574655999, 574307808, 574656169, 625513999],
            array_column($data['goals'], 'id'),
            'ровно четыре живые цели и в заданном порядке',
        );
        $this->assertFalse($data['blind']['gsc_connected']);
        $this->assertSame([], $response->json('meta.errors'));
    }

    /**
     * Плитка переходов считает посты со статусом `posted`.
     *
     * Считала по `sent` — такого статуса у записи нет вовсе, поэтому счётчик
     * молча отдавал нули, и весь блок «телеграм в обе стороны» на главной
     * выглядел пустым: как будто по постам не переходят.
     */
    public function test_плитка_переходов_видит_опубликованные_посты(): void
    {
        $this->fakeAll();
        $this->seedPostedPosts();

        $telegram = $this->warmAndGet()->json('data.telegram');

        $this->assertSame(3, $telegram['posts_sent'], 'три поста ушли в канал');
        $this->assertSame(2, $telegram['posts_measured'], 'у двух переходы посчитаны');
        $this->assertSame(1, $telegram['posts_with_clicks'], 'у одного они ненулевые');
        $this->assertSame(7, $telegram['post_clicks']);
    }

    /** Посты за пределами окна и неопубликованные в плитку не попадают. */
    public function test_плитка_переходов_не_берёт_чужое(): void
    {
        $this->fakeAll();
        $this->seedPostedPosts();

        $this->broadcastItem(status: 'posted', postedAt: CarbonImmutable::now()->subDays(60), clicks: 100);
        $this->broadcastItem(status: 'pending', postedAt: null, clicks: null);
        $this->broadcastItem(status: 'skipped', postedAt: CarbonImmutable::now()->subDay(), clicks: 50);

        $telegram = $this->warmAndGet()->json('data.telegram');

        $this->assertSame(3, $telegram['posts_sent'], 'старое, неотправленное и пропущенное не считаются');
        $this->assertSame(7, $telegram['post_clicks']);
    }

    private function seedPostedPosts(): void
    {
        $this->broadcastItem(status: 'posted', postedAt: CarbonImmutable::now()->subDays(3), clicks: 7);
        $this->broadcastItem(status: 'posted', postedAt: CarbonImmutable::now()->subDays(2), clicks: 0);
        $this->broadcastItem(status: 'posted', postedAt: CarbonImmutable::now()->subDay(), clicks: null);
    }

    private function broadcastItem(string $status, ?CarbonImmutable $postedAt, ?int $clicks): void
    {
        $broadcastId = DB::table('telegram.chat_broadcasts')->value('id')
            ?? $this->seedBroadcast();

        DB::table('telegram.chat_broadcast_items')->insert([
            'broadcast_id' => $broadcastId,
            'kind' => 'event',
            'status' => $status,
            'posted_at' => $postedAt,
            'clicks' => $clicks,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBroadcast(): int
    {
        $chatId = DB::table('telegram.chats')->insertGetId([
            'telegram_chat_id' => -1001234567890,
            'chat_type' => 'channel',
            'title' => 'Тестовый канал',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('telegram.chat_broadcasts')->insertGetId([
            'chat_id' => $chatId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_пустой_кэш_не_роняет_страницу(): void
    {
        $this->fakeAll();

        $response = $this->getJson(self::URL)->assertOk();

        $this->assertNull($response->json('data.search'), 'нулями пустоту не подменяем');
        $this->assertTrue($response->json('meta.rebuilding'), 'пересчёт должен быть заказан');
        $this->assertTrue(
            Cache::has(AnalyticsWarmCommand::REQUEST_FLAG),
            'флажок для планировщика не выставлен — страница осталась бы пустой навсегда',
        );
    }

    public function test_молчащий_вебмастер_не_роняет_ответ(): void
    {
        $this->fakeAll(webmasterStatus: 503);

        $data = $this->warmAndGet()->json();

        $this->assertNull($data['data']['chain']['index_pages'], 'индекса нет — значит null, а не ноль');
        $this->assertNull($data['data']['blind']['webmaster_shows']);
        $this->assertSame(851, $data['data']['search']['visits'], 'Метрика не пострадала');
        $this->assertSame(1, $data['data']['chain']['cards_ahead'], 'карточки считаются из своей базы');

        $sections = array_column($data['meta']['errors'], 'section');
        $this->assertContains('index', $sections);
        $this->assertStringContainsString('503', implode(' ', array_column($data['meta']['errors'], 'message')));
    }

    public function test_фильтр_служебного_трафика_уходит_в_каждый_запрос(): void
    {
        $this->fakeAll();
        $this->warmAndGet();

        $seen = 0;
        Http::assertSent(function (Request $request) use (&$seen) {
            if (! str_contains($request->url(), 'api-metrika.yandex.net')) {
                return false;
            }

            $seen++;
            $filters = (string) ($this->query($request)['filters'] ?? '');

            $this->assertMatchesRegularExpression(
                "~ym:s:browser(!=|==)'204'~",
                $filters,
                'запрос к Метрике ушёл без фильтра служебного трафика: '.$filters,
            );

            return true;
        });

        $this->assertGreaterThanOrEqual(7, $seen, 'отчёт собирается не одним запросом — проверить надо все');
    }

    public function test_текущая_неделя_в_ряд_не_попадает(): void
    {
        $this->fakeAll();

        $weeks = $this->warmAndGet()->json('data.search.weeks');

        $monday = CarbonImmutable::now()->startOfWeek()->toDateString();

        $this->assertCount(12, $weeks);
        foreach ($weeks as $week) {
            $this->assertLessThan($monday, $week['to'], 'незакрытая неделя не отдаётся вовсе');
        }

        $this->assertTrue(
            $weeks[11]['provisional'],
            'последнюю закрытую Метрика ещё доуточнит — она помечена',
        );
        $this->assertFalse($weeks[10]['provisional']);
    }

    public function test_визит_на_карточку_доходит_до_источника(): void
    {
        $this->fakeAll();

        $rows = $this->warmAndGet()->json('data.card_sources');

        $this->assertCount(1, $rows);
        $this->assertSame('vk', $rows[0]['key']);
        $this->assertSame(46, $rows[0]['visits_30d']);
        $this->assertSame(1, $rows[0]['events_matched']);
        $this->assertSame(1, $rows[0]['cards_ahead']);
    }

    // ------------------------------------------------------------------ Стенд

    /** Событие в будущем с источником-сообществом ВК: минимальная живая цепочка. */
    private function seedFutureEvent(): void
    {
        DB::table('social_networks')->updateOrInsert(['id' => 1], ['slug' => 'vk', 'name' => 'vk']);

        DB::insert(
            "INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES ('Воронеж','RU', ST_SetSRID(ST_Point(39.2,51.66),4326),'active','voronezh', now(), now())"
        );
        $cityId = (int) DB::table('cities')->where('slug', 'voronezh')->value('id');

        $communityId = (int) DB::table('communities')->insertGetId([
            'name' => 'Паблик', 'city_id' => $cityId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $linkId = (int) DB::table('community_social_links')->insertGetId([
            'community_id' => $communityId, 'social_network_id' => 1, 'url' => 'https://vk.com/pablik',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Время события берём от now() САМОЙ БАЗЫ, а не от PHP: иначе тест
        // протухает вместе с датой, на которую его писали.
        $this->eventId = (int) DB::table('events')->insertGetId([
            'community_id' => $communityId,
            'city_id' => $cityId,
            'title' => 'Концерт',
            'status' => 'active',
            'start_time' => DB::raw("now() + interval '10 days'"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('event_sources')->insert([
            'event_id' => $this->eventId, 'social_link_id' => $linkId,
            'source' => 'vk', 'post_external_id' => 'wall-1_1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    private function fakeAll(int $webmasterStatus = 200): void
    {
        $now = CarbonImmutable::now();
        $monday = $now->startOfWeek();
        $curFrom = $now->subDays(29)->toDateString();

        // Двенадцать закрытых недель Пн–Вс плюс оборванная текущая: ровно то,
        // что Метрика отдаёт на group=week в любой день, кроме воскресенья.
        $intervals = [];
        for ($i = 12; $i >= 1; $i--) {
            $start = $monday->subWeeks($i);
            $intervals[] = [$start->toDateString(), $start->addDays(6)->toDateString()];
        }
        $intervals[] = [$monday->toDateString(), $now->toDateString()];

        $organic = [23, 39, 63, 73, 119, 136, 103, 183, 272, 159, 229, 149, 41];
        $yandex = [1, 0, 0, 1, 10, 20, 22, 32, 134, 88, 130, 95, 25];
        $google = [22, 39, 63, 72, 109, 116, 81, 151, 138, 71, 99, 54, 16];

        $headlessDays = [];
        $headlessValues = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = $now->subDays($i);
            $headlessDays[] = [$day->toDateString(), $day->toDateString()];
            $headlessValues[] = $i === 5 ? 201 : 2;
        }
        $spikeDate = $now->subDays(5)->toDateString();

        Http::fake([
            'api-metrika.yandex.net/stat/v1/data/bytime*' => function (Request $request) use ($intervals, $organic, $yandex, $google, $headlessDays, $headlessValues) {
                $q = $this->query($request);

                if (($q['group'] ?? '') === 'day') {
                    return Http::response([
                        'time_intervals' => $headlessDays,
                        'data' => [['dimensions' => [], 'metrics' => [$headlessValues]]],
                    ]);
                }

                return Http::response([
                    'time_intervals' => $intervals,
                    'totals' => [$organic],
                    'data' => [
                        ['dimensions' => [['id' => 'google', 'name' => 'Google']], 'metrics' => [$google]],
                        ['dimensions' => [['id' => 'yandex', 'name' => 'Yandex']], 'metrics' => [$yandex]],
                    ],
                ]);
            },

            'api-metrika.yandex.net/stat/v1/data*' => function (Request $request) use ($curFrom) {
                $q = $this->query($request);
                $dimensions = (string) ($q['dimensions'] ?? '');
                $metrics = (string) ($q['metrics'] ?? '');

                if ($dimensions === 'ym:s:startURLPath') {
                    return Http::response(['totals' => [851], 'data' => [
                        ['dimensions' => [['name' => '/events/'.$this->eventId]], 'metrics' => [40]],
                        // Тот же адрес с хвостом запроса: склеиться обязан, иначе
                        // одна страница считается двумя и тянет медиану вниз.
                        ['dimensions' => [['name' => '/events/'.$this->eventId.'?src=past']], 'metrics' => [6]],
                        ['dimensions' => [['name' => '/events/999999']], 'metrics' => [9]],
                        ['dimensions' => [['name' => '/afisha/voronezh/na-vyhodnyh']], 'metrics' => [4]],
                        ['dimensions' => [['name' => '/venues/nekrasova']], 'metrics' => [2]],
                        ['dimensions' => [['name' => '/']], 'metrics' => [1]],
                    ]]);
                }

                if ($dimensions === 'ym:s:lastsignSearchEngineRoot') {
                    return Http::response(['totals' => [851], 'data' => [
                        ['dimensions' => [['id' => 'yandex', 'name' => 'Yandex']], 'metrics' => [473]],
                        ['dimensions' => [['id' => 'google', 'name' => 'Google']], 'metrics' => [378]],
                    ]]);
                }

                if (str_contains($metrics, 'goal')) {
                    return Http::response(['totals' => [873, 275, 42, 14]]);
                }

                if (str_contains($metrics, 'ym:s:users')) {
                    return ($q['date1'] ?? '') === $curFrom
                        ? Http::response(['totals' => [851, 770]])
                        : Http::response(['totals' => [558, 505]]);
                }

                return Http::response(['totals' => [999]]);
            },

            'api.webmaster.yandex.net/*search-urls*' => $webmasterStatus !== 200
                ? Http::response(['error' => 'oops'], $webmasterStatus)
                : Http::response(['history' => [
                    ['date' => $now->subDays(32)->toIso8601String(), 'value' => 566],
                    ['date' => $now->subDays(2)->toIso8601String(), 'value' => 1155],
                ]]),

            'api.webmaster.yandex.net/*search-queries*' => $webmasterStatus !== 200
                ? Http::response(['error' => 'oops'], $webmasterStatus)
                : Http::response(['indicators' => [
                    'TOTAL_SHOWS' => [['date' => $now->subDays(2)->toIso8601String(), 'value' => 14673]],
                    'TOTAL_CLICKS' => [['date' => $now->subDays(2)->toIso8601String(), 'value' => 489]],
                ]]),
        ]);

        $this->spikeDate = $spikeDate;
    }

    private string $spikeDate = '';
}
