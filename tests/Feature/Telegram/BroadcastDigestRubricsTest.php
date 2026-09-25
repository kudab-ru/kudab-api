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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Рубрики не по теме, а по поводу: «Бесплатно», «Дешевле 500», «Вечером».
 *
 * Всё остальное в подборках отвечает на вопрос «что идёт», эти три — на
 * «почему именно сейчас». Отбор у них не по интересу, поэтому и правила
 * другие: жанровые отсечки не применяются (у «Бесплатно» жанра нет), окно
 * может быть короче недели, а число названных плавает.
 *
 * Замер, из которого всё это выросло (25.09.2026): бесплатного 35 событий в
 * ближайшую неделю и 4 в следующую — недельное окно для такой рубрики почти
 * пустое.
 */
class BroadcastDigestRubricsTest extends TestCase
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

    #[Test]
    public function the_free_rubric_takes_events_of_any_genre(): void
    {
        $this->onlyRubric('besplatno');

        // Разные жанры намеренно: у «Бесплатно» жанра нет, и концерт рядом с
        // лекцией — это не сбой отбора, а смысл рубрики. Тематическая
        // подборка такую смесь отвергла бы жанровыми отсечками.
        $this->fill('Бесплатное', free: true);
        $this->event('Концерт под открытым небом', 1, free: true, hour: 19);
        $this->event('Лекция о металлургии', 2, free: true, hour: 19);

        $out = app(BroadcastDigestComposer::class)->compose($this->channel(), Carbon::now());

        $this->assertNotNull($out);
        $this->assertSame('besplatno', $out['theme_slug']);
        $this->assertGreaterThanOrEqual(3, count($out['event_ids']));
    }

    /** Платное в бесплатную рубрику не попадает — это обещание, а не оттенок. */
    #[Test]
    public function a_paid_event_never_enters_the_free_rubric(): void
    {
        $this->onlyRubric('besplatno');

        $this->fill('Бесплатное', free: true);
        $paid = $this->event('Концерт за деньги', 1, free: false, priceMin: 1500);

        $out = app(BroadcastDigestComposer::class)->compose($this->channel(), Carbon::now());

        $this->assertNotContains($paid, $out['event_ids']);
    }

    /**
     * Окно у «Бесплатно» короче недели.
     *
     * Иначе рубрика тянула бы события, которых на момент выхода ещё нет в
     * афише: бесплатное объявляют за пару дней.
     */
    #[Test]
    public function the_free_rubric_looks_three_days_ahead(): void
    {
        $this->onlyRubric('besplatno');

        $this->fill('Бесплатное', free: true);
        $far = $this->event('Событие через неделю', 6, free: true);

        $out = app(BroadcastDigestComposer::class)->compose($this->channel(), Carbon::now());

        $this->assertNotContains($far, $out['event_ids'], 'за окно рубрики не заглядываем');
    }

    /**
     * Число названных плавает, но сверх минимума берём только тех, кому есть
     * что сказать: иначе четвёртым встанет голое «название · дата · место».
     */
    #[Test]
    public function the_fourth_line_is_taken_only_with_a_full_description(): void
    {
        $this->onlyRubric('besplatno');

        // Три полных на трёх днях плюс добивка до гейта, и ЧЕТВЁРТЫЙ день —
        // с пустой карточкой: именно его брать не должны.
        $this->fill('Бесплатное', free: true);
        $thin = $this->event('Событие без описания', 4, free: true, description: 'Коротко.');

        $out = app(BroadcastDigestComposer::class)->compose($this->channel(), Carbon::now());

        $this->assertCount(3, $out['event_ids'], 'четвёртого с пустой карточкой не берём');
        $this->assertNotContains($thin, $out['event_ids']);
    }

    #[Test]
    public function a_fourth_full_event_is_taken(): void
    {
        $this->onlyRubric('besplatno');

        $this->fill('Бесплатное', free: true);
        $this->event('Полное четвёртого дня', 4, free: true);

        // Окно у «Бесплатно» трёхдневное — четвёртый день в него не попадёт,
        // поэтому для этой проверки окно расширяем до недели: речь про число
        // названных, а не про срок.
        config(['broadcast_digest.themes' => array_map(
            static function (array $t) {
                $t['window_days'] = 7;

                return $t;
            },
            (array) config('broadcast_digest.themes'),
        )]);

        $out = app(BroadcastDigestComposer::class)->compose($this->channel(), Carbon::now());

        $this->assertCount(4, $out['event_ids'], 'четвёртого с полной карточкой берём');
    }

    /** «Дешевле 500» выходит только в неделю, где даром нечего. */
    #[Test]
    public function the_cheap_rubric_waits_until_free_has_nothing(): void
    {
        $this->onlyRubric('besplatno', 'deshevle-500');

        $this->fill('Бесплатное', free: true);
        $this->fill('Недорогое', free: false, priceMin: 300);

        $out = app(BroadcastDigestComposer::class)->compose($this->channel(), Carbon::now());

        $this->assertSame('besplatno', $out['theme_slug'], 'бесплатное первым');
    }

    #[Test]
    public function without_free_events_the_cheap_rubric_steps_in(): void
    {
        $this->onlyRubric('besplatno', 'deshevle-500');

        $this->fill('Недорогое', free: false, priceMin: 300);

        $out = app(BroadcastDigestComposer::class)->compose($this->channel(), Carbon::now());

        $this->assertSame('deshevle-500', $out['theme_slug']);
    }

    /** Цена неизвестна — в рубрику про деньги не пускаем: обещание проверят. */
    #[Test]
    public function an_event_with_unknown_price_is_not_called_cheap(): void
    {
        $this->onlyRubric('deshevle-500');

        $this->fill('Недорогое', free: false, priceMin: 400);
        $unknown = $this->event('Цена неизвестна', 1, free: false, priceMin: null);

        $out = app(BroadcastDigestComposer::class)->compose($this->channel(), Carbon::now());

        $this->assertNotContains($unknown, $out['event_ids']);
    }

    /**
     * Рубрика, вышедшая недавно, уступает место другой.
     *
     * Канал с одной и той же «неделей спектаклей» читается как заевшая
     * пластинка — это и есть «ориентироваться по постам на неделе».
     */
    #[Test]
    public function a_recently_used_rubric_yields_to_another(): void
    {
        $this->onlyRubric('besplatno', 'vecherom');
        $channel = $this->channel();

        // Обе рубрики набираются: без ротации победила бы любая, и тест не
        // отличал бы правило от случайности. Час у бесплатного утренний —
        // иначе третий день не влезет в его трёхдневное окно.
        $this->fill('Бесплатное', free: true);
        $this->fill('Вечернее', free: false, priceMin: 900, hour: 21);

        $this->postedDigest($channel, 'besplatno');

        $out = app(BroadcastDigestComposer::class)->compose($channel, Carbon::now());

        $this->assertSame('vecherom', $out['theme_slug'], 'недавняя рубрика уступает');
    }

    /* ───────────────────────── обстановка ───────────────────────── */

    /**
     * Набить рубрику до гейта: ей нужно 5 событий на 3 площадках.
     *
     * Раскладываем по дням 1–3 и НА РАННЕЕ УТРО: окно «Бесплатно»
     * трёхдневное и отсчитывается от ЧАСА выхода (полдень), поэтому третий
     * день попадает в него только до полудня. Площадка у каждого своя, день повторяется — названных всё
     * равно будет не больше, чем дней (одна строка на день).
     */
    private function fill(string $prefix, bool $free, ?int $priceMin = null, int $hour = 8): void
    {
        foreach ([1, 1, 2, 2, 3, 3] as $i => $day) {
            $this->event($prefix.' '.($i + 1), $day, free: $free, priceMin: $priceMin, hour: $hour);
        }
    }

    private function onlyRubric(string ...$slugs): void
    {
        config(['broadcast_digest.themes' => array_values(array_filter(
            (array) config('broadcast_digest.themes', []),
            static fn (array $t) => in_array($t['slug'] ?? '', $slugs, true),
        ))]);
    }

    private function postedDigest(TelegramChatBroadcast $channel, string $themeSlug): void
    {
        DB::table('telegram.chat_broadcast_items')->insert([
            'broadcast_id' => $channel->id,
            'kind' => TelegramChatBroadcastItem::KIND_DIGEST,
            'status' => TelegramChatBroadcastItem::STATUS_POSTED,
            'posted_at' => now()->subDays(7),
            'digest_meta' => json_encode(['theme' => $themeSlug], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subDays(7),
            'updated_at' => now()->subDays(7),
        ]);
    }

    private function event(
        string $title,
        int $dayOffset,
        bool $free,
        ?int $priceMin = null,
        int $hour = 19,
        ?string $description = null,
    ): int {
        $community = \App\Models\Community::create([
            'name' => 'Организатор '.uniqid(),
            'city_id' => $this->cityId,
        ]);

        $venueId = DB::table('venues')->insertGetId([
            'city_id' => $this->cityId,
            'name' => 'Площадка '.uniqid(),
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
        // Час задаём ПО МОСКВЕ и переводим в UTC ЯВНО. Приложение живёт в UTC,
        // а запись даты идёт форматированием без смены пояса: 21:00 по Москве
        // ложилось в базу как 21:00 UTC, то есть полночь по Москве, и рубрика
        // «Вечером» не набиралась вовсе.
        $at = Carbon::now('Europe/Moscow')->addDays($dayOffset)->setTime($hour, 0)->utc();

        $event->start_time = $at;
        $event->start_date = $at->toDateString();
        $event->end_time = $at->copy()->addHours(2);
        $event->description = $description ?? str_repeat('описание события достаточной длины. ', 5);
        $event->price_status = $free ? 'free' : ($priceMin === null ? 'unknown' : 'range');
        $event->price_min = $free ? 0 : $priceMin;
        $event->save();

        DB::table('event_interest')->insert([
            'event_id' => $event->id,
            'interest_id' => $this->interestId,
            'rank' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $event->id;
    }

    private ?TelegramChatBroadcast $channel = null;

    /** Свойство, а не static в методе: тот переживает смену теста и утекает. */
    private function channel(): TelegramChatBroadcast
    {
        return $this->channel ??= $this->makeChannel();
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $user = TelegramUser::create([
            'telegram_id' => random_int(10_000, 99_999),
            'first_name' => 'Владелец',
        ]);

        // Через свойства, а не create(): city_id у модели чата не в fillable,
        // и массовое присвоение молча его теряло — канал оставался без города,
        // а отбор без города не работает вовсе.
        $chat = new TelegramChat;
        $chat->telegram_user_id = $user->id;
        $chat->telegram_chat_id = -random_int(1_000_000, 9_999_999);
        $chat->chat_type = 'channel';
        $chat->title = 'Канал рубрик';
        $chat->is_active = true;
        $chat->city_id = $this->cityId;
        $chat->save();

        $b = new TelegramChatBroadcast;
        $b->chat_id = $chat->id;
        $b->enabled = true;
        $b->settings = ['ai_text' => false, 'slots' => [19], 'feed_limit' => 20];
        $b->save();

        return $b->fresh(['chat.city']);
    }

    private function city(): int
    {
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.66, 'active', 'voronezh-rubrics', now(), now()]
        );

        return (int) DB::table('cities')->where('slug', 'voronezh-rubrics')->value('id');
    }
}
