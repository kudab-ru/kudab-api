<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Services\Telegram\BroadcastDigestComposer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Композитор подборки: тема, состав, текст.
 *
 * Собирается перед самой отправкой — собранная заранее подборка показывает
 * пятую часть недели. Отбор обязан вычитать то, что канал уже показал, иначе
 * подборка становится оглавлением прочитанного.
 */
class BroadcastDigestComposerTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private int $interestId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        $this->cityId = $this->city();
        $this->interestId = (int) DB::table('interests')->insertGetId([
            'name' => 'Театр', 'slug' => 'theatre', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_composes_a_theme_with_three_named_events(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out, 'шести событий на шести площадках хватает на тему');
        $this->assertSame('spektakli', $out['theme']['slug']);
        $this->assertCount(3, $out['event_ids'], 'называем три события');
        $this->assertSame(6, $out['total']);
        $this->assertStringContainsString('Спектакли недели', $out['caption']);
        $this->assertStringContainsString('6 спектаклей', $out['caption'], 'число склоняется');
        $this->assertStringContainsString('Вся афиша спектаклей', $out['caption']);
    }

    /** Гейт: меньше пяти событий — это не подборка, а слабая лента. */
    public function test_thin_theme_does_not_pass_the_gate(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 3) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $this->assertNull(app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now()));
    }

    /**
     * События, которые канал уже показывает, в подборку не попадают — иначе
     * она становится оглавлением уже прочитанного.
     */
    public function test_events_already_in_the_feed_are_subtracted(): void
    {
        $broadcast = $this->makeChannel();
        $events = [];
        foreach (range(1, 6) as $n) {
            $events[] = $this->themedEvent("Спектакль {$n}", $n);
        }

        // Первое уже стоит в ленте канала обычным постом.
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $item->event_id = $events[0];
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->save();

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertNotContains($events[0], $out['event_ids']);
        $this->assertSame(5, $out['total'], 'занятое событие вычтено и из счётчика');
    }

    /**
     * Событие, которое в первой строке описания называет ЧУЖОЙ жанр, в тему не
     * идёт — какой бы тег ему ни поставила разметка.
     *
     * Живой случай: квест «Припять 36» попал в подборку концертов. Первичная
     * тема «музыка» проставлена ему 235 раз подряд, то есть переголосовать её
     * повторами нельзя — они ошибаются одинаково. Зато в первой строке описания
     * прямым текстом «квеста».
     */
    public function test_event_naming_a_foreign_genre_is_rejected(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 5) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $quest = $this->themedEvent('Припять 36', 6);
        DB::table('events')->where('id', $quest)->update([
            'description' => 'Главный герой квеста «Припять 36» в Воронеже — учёный Адрианов, '
                .'много лет изучавший аномалии зоны отчуждения и оставивший дневники.',
        ]);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertNotContains($quest, $out['event_ids'], 'квест не место в подборке спектаклей');
        $this->assertSame(5, $out['total'], 'и в счётчике его тоже нет');
    }

    /**
     * Событие без площадки поимённо не называем.
     *
     * Такая строка не блокировалась правилом «одна площадка — одна строка» и
     * сама площадку не занимала: три события без места прошли бы все отсечки и
     * встали рядом. А читателю «название · дата» без места говорит половину.
     */
    public function test_event_without_a_venue_is_never_named(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 5) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        // Самое длинное описание — то есть первый кандидат по нынешнему отбору.
        $homeless = $this->themedEvent('Спектакль без места', 6);
        DB::table('events')->where('id', $homeless)->update([
            'venue_id' => null,
            'description' => str_repeat('очень длинное описание события. ', 20),
        ]);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertNotContains($homeless, $out['event_ids']);
    }

    /**
     * Подпись считает названных, а не обещает «Три».
     *
     * Гейт темы — пять событий, но отсечки «одна площадка, один день» могут
     * оставить меньше трёх. Текст при этом обещал три и отправлялся молча.
     */
    public function test_caption_counts_the_named_instead_of_promising_three(): void
    {
        $broadcast = $this->makeChannel();
        // Пять событий, но все в один день: отсечка по дню оставит одно.
        foreach (range(1, 5) as $n) {
            $id = $this->themedEvent("Спектакль {$n}", $n);
            DB::table('events')->where('id', $id)->update([
                'start_time' => Carbon::now()->addDays(2)->setTime(19, 0),
            ]);
        }

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertCount(1, $out['event_ids']);
        $this->assertStringContainsString('Одно —', $out['caption']);
        $this->assertStringNotContainsString('Три —', $out['caption']);
    }

    /** Названные события идут по датам — подборка про «что впереди». */
    public function test_named_events_are_ordered_by_date(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $starts = DB::table('events')->whereIn('id', $out['event_ids'])
            ->orderByRaw('array_position(ARRAY['.implode(',', $out['event_ids']).']::bigint[], id)')
            ->pluck('start_time')->map(fn ($t) => (string) $t)->all();

        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts, 'порядок в тексте — хронологический');
    }

    /** Заголовок не по теме в подборку не идёт, даже с нужным тегом. */
    public function test_stop_list_rejects_off_theme_titles(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 5) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        // День 6 — внутри недельного окна: с днём за окном тест не проверял бы
        // стоп-лист вовсе, событие отсекалось бы окном.
        $masterClass = $this->themedEvent('Мастер-класс по сценречи', 6);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertNotContains($masterClass, $out['event_ids']);
        $this->assertSame(5, $out['total']);
    }

    /**
     * «Собрать и править»: текст и состав записываются в саму запись, и
     * названные события сразу закрываются для обычных постов.
     */
    public function test_compose_now_fills_the_item_and_links_events(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = new TelegramChatBroadcastItem;
        $digest->broadcast_id = $broadcast->id;
        $digest->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $digest->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $digest->publish_at = Carbon::now()->addDay();
        $digest->save();

        $res = $this->postJson("/api/admin/broadcast/items/{$digest->id}/compose");

        $res->assertOk();
        $this->assertStringContainsString('Спектакли недели', (string) $res->json('data.caption'));
        $this->assertCount(3, $res->json('data.linked_events'), 'состав виден в форме');

        $this->assertSame(3, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $digest->id)->count(), 'события закрыты для лент');
        $this->assertSame(0, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $digest->id)->where('position', 0)->count(),
            'позиция 0 занята ведущим событием обычного поста — у подборки её нет');
    }

    /**
     * Подборка уходит в канал С КАРТИНКАМИ.
     *
     * Первая живая подборка ушла голым текстом: обложки названных событий
     * собирались только для показа в админке, а путь доставки о них не знал и
     * слал пустой список. Тест сторожит именно этот разрыв.
     */
    public function test_digest_goes_out_with_covers_of_named_events(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n, withImage: true);
        }

        $digest = new TelegramChatBroadcastItem;
        $digest->broadcast_id = $broadcast->id;
        $digest->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $digest->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $digest->publish_at = Carbon::now()->subMinute();
        $digest->save();

        $tasks = app(\App\Services\Telegram\TelegramChatBroadcastService::class)
            ->collectDueSingleRuns(Carbon::now());

        $task = collect($tasks)->firstWhere('item_id', $digest->id);

        $this->assertNotNull($task, 'подборка должна уйти в выдачу боту');
        $this->assertSame('digest', $task['kind']);
        $this->assertNotEmpty($task['photo_urls'], 'обложки названных событий обязаны доехать до бота');
        $this->assertNotNull($task['photo_url'], 'и обложка тоже');
    }

    private function themedEvent(string $title, int $n, bool $withImage = false): int
    {
        $community = \App\Models\Community::create([
            'name' => 'Организатор '.uniqid(),
            'city_id' => $this->cityId,
        ]);

        $venueId = DB::table('venues')->insertGetId([
            'city_id' => $this->cityId,
            'name' => 'Площадка '.$n.' '.uniqid(),
            'slug' => 'venue-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new \App\Models\Event;
        $event->community_id = $community->id;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $this->cityId;
        $event->venue_id = $venueId;
        // Разные дни: отсечка «одна строка на день» иначе оставила бы одно.
        $event->start_time = Carbon::now()->addDays($n)->setTime(19, 0);
        $event->start_date = Carbon::now()->addDays($n)->toDateString();
        $event->end_time = Carbon::now()->addDays($n)->setTime(21, 0);
        // Длина описания РАСТЁТ с номером дня: отбор идёт по полноте карточки,
        // значит без сортировки на выходе получится обратный хронологии
        // порядок — иначе тест на порядок проходил бы сам собой.
        $event->description = str_repeat('описание события достаточной длины. ', 4 + $n);
        $event->price_min = 500 * $n;
        $event->save();

        if ($withImage) {
            // Картинки события лежат в event_sources.images — оттуда их берёт
            // и админка, и выдача задачи боту.
            DB::table('event_sources')->insert([
                'event_id' => $event->id,
                'social_link_id' => $this->socialLink($community->id),
                'source' => 'vk',
                'post_external_id' => 'post-'.uniqid(),
                'images' => json_encode(['https://example.test/cover-'.$n.'.jpg']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('event_interest')->insert([
            'event_id' => $event->id,
            'interest_id' => $this->interestId,
            'rank' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $event->id;
    }

    /** Источник постов сообщества — обязательная ссылка у event_sources. */
    private function socialLink(int $communityId): int
    {
        $networkId = DB::table('social_networks')->value('id')
            ?? DB::table('social_networks')->insertGetId([
                'name' => 'VK', 'slug' => 'vk', 'created_at' => now(), 'updated_at' => now(),
            ]);

        return (int) DB::table('community_social_links')->insertGetId([
            'community_id' => $communityId,
            'social_network_id' => $networkId,
            'url' => 'https://vk.com/'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201999]);
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999088;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $this->cityId;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ])->load('chat.city');
    }

    private function city(): int
    {
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh-digest', now(), now()]
        );

        return (int) DB::table('cities')->where('slug', 'voronezh-digest')->value('id');
    }
}
