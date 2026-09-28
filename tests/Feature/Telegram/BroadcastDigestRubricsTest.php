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
     * Окно у «Бесплатно» короче недели, но не короче пяти дней.
     *
     * Строка в подборке одна на день, поэтому названных не бывает больше, чем
     * дней в окне. На трёх днях рубрика не дотягивала до обещанного минимума
     * в три события, а раз в четыре выхода не набиралась совсем — замеры в
     * докблоке `window_days` в config/broadcast_digest.php.
     */
    #[Test]
    public function the_free_rubric_does_not_look_a_whole_week_ahead(): void
    {
        $this->onlyRubric('besplatno');

        $this->fill('Бесплатное', free: true);
        $far = $this->event('Событие через неделю', 7, free: true);

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

    /* ───────────── подборка вне очереди ───────────── */

    /**
     * Кнопка ставит ЕЩЁ ОДНУ подборку, не трогая недельную.
     *
     * Вне сетки — и это не косметика: слот дня остаётся свободным для обычных
     * постов, а очередная недельная подборка не отменяется. Без признака
     * кнопка тихо съедала бы следующую по расписанию.
     */
    #[Test]
    public function the_off_grid_digest_is_marked_and_composed(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);
        $channel = $this->channel();
        $this->actingAsSuperadmin();

        $res = $this->postJson("/api/admin/broadcast/channels/{$channel->id}/digest-now")
            ->assertCreated();

        $item = DB::table('telegram.chat_broadcast_items')
            ->where('broadcast_id', $channel->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->first();

        $this->assertTrue((bool) $item->is_off_grid, 'подборка вне сетки');
        $this->assertNotNull($item->caption, 'состав и подпись собраны сразу');
        $this->assertSame(
            'besplatno',
            json_decode((string) $item->digest_meta, true)['theme'] ?? null,
        );
        $this->assertGreaterThanOrEqual(3, count($res->json('data.linked_events')));
    }

    /** Внесеточная подборка не отменяет очередную недельную. */
    #[Test]
    public function an_off_grid_digest_does_not_cancel_the_weekly_one(): void
    {
        $channel = $this->channel();

        DB::table('telegram.chat_broadcast_items')->insert([
            'broadcast_id' => $channel->id,
            'kind' => TelegramChatBroadcastItem::KIND_DIGEST,
            'status' => TelegramChatBroadcastItem::STATUS_PENDING,
            'publish_at' => now()->addMinutes(10),
            'is_off_grid' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $channel->period = 'daily_19';
        $channel->digest_slots = [['weekday' => 4, 'hour' => 19]];
        $channel->save();

        app(\App\Services\Telegram\BroadcastDigestBooking::class)->bookDue(Carbon::now());

        $this->assertSame(
            1,
            TelegramChatBroadcastItem::query()
                ->where('broadcast_id', $channel->id)
                ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
                ->where('is_off_grid', false)
                ->count(),
            'внесеточная не мешает недельной встать',
        );
    }

    /** Два слота — две брони в неделю, каждая на свой день и час. */
    #[Test]
    public function two_slots_book_two_digests(): void
    {
        $channel = $this->channel();
        $channel->period = 'daily_19';
        $channel->digest_slots = [
            ['weekday' => 4, 'hour' => 19],
            ['weekday' => 5, 'hour' => 14, 'theme' => 'na-vyhodnyh'],
        ];
        $channel->save();

        app(\App\Services\Telegram\BroadcastDigestBooking::class)->bookDue(Carbon::now());

        $booked = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $channel->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->get();

        $this->assertCount(2, $booked);

        $shape = $booked
            ->map(fn ($i) => Carbon::parse($i->publish_at)->setTimezone('Europe/Moscow'))
            ->map(fn ($t) => $t->isoWeekday().'@'.$t->hour)
            ->sort()->values()->all();

        $this->assertSame(['4@19', '5@14'], $shape);

        $friday = $booked->first(fn ($i) => Carbon::parse($i->publish_at)->setTimezone('Europe/Moscow')->isoWeekday() === 5);
        $this->assertSame('na-vyhodnyh', ($friday->digest_meta ?? [])['theme_wanted'] ?? null);
    }

    /** Рубрика слота доходит до сборки состава. */
    #[Test]
    public function a_slot_rubric_is_used_when_the_digest_is_filled(): void
    {
        $this->onlyRubric('besplatno', 'spektakli');
        $this->fill('Бесплатное', free: true);
        foreach ([1, 2, 3, 4, 5] as $day) {
            $this->event('Спектакль '.$day, $day, free: false, priceMin: 700);
        }

        $channel = $this->channel();
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $channel->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = Carbon::now()->addHours(6);
        $item->digest_meta = ['theme_wanted' => 'spektakli'];
        $item->save();

        app(\App\Services\Telegram\TelegramChatBroadcastService::class)
            ->prepareDigestAhead($item, $channel, Carbon::now());

        $this->assertSame('spektakli', ($item->fresh()->digest_meta ?? [])['theme'] ?? null);
    }

    /** Повторный прогон не плодит дубли: на слот одна бронь. */
    #[Test]
    public function booking_twice_keeps_one_per_slot(): void
    {
        $channel = $this->channel();
        $channel->period = 'daily_19';
        $channel->digest_slots = [['weekday' => 4, 'hour' => 19], ['weekday' => 5, 'hour' => 14]];
        $channel->save();

        $booking = app(\App\Services\Telegram\BroadcastDigestBooking::class);
        $booking->bookDue(Carbon::now());
        $booking->bookDue(Carbon::now());

        $this->assertSame(2, TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $channel->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->count());
    }

    /** Старая настройка одним днём читается как один слот. */
    #[Test]
    public function the_legacy_weekday_reads_as_one_slot(): void
    {
        $channel = $this->channel();
        $settings = $channel->settings;
        unset($settings['digest_slots']);
        $settings['digest_weekday'] = 4;
        $channel->settings = $settings;
        $channel->save();

        $slots = $channel->fresh()->digest_slots;

        $this->assertCount(1, $slots);
        $this->assertSame(4, $slots[0]['weekday']);
        $this->assertSame(19, $slots[0]['hour'], 'час по старой раскладке — последний слот канала');
    }

    /** Ставить нечего — говорим прямо, а не создаём пустую запись. */
    #[Test]
    public function nothing_to_post_creates_nothing(): void
    {
        $this->onlyRubric('besplatno');
        $this->actingAsSuperadmin();
        $channel = $this->channel();

        $this->postJson("/api/admin/broadcast/channels/{$channel->id}/digest-now")
            ->assertStatus(422);

        $this->assertSame(0, DB::table('telegram.chat_broadcast_items')
            ->where('broadcast_id', $channel->id)->count());
    }

    /* ──────────────── как выглядит строка события ──────────────── */

    /**
     * Короткий день недели, пока суббота в составе одна.
     *
     * «сб 19:00» против «сб 19 сентября, 19:00» — двенадцать знаков на строке,
     * то есть место под целое шестое событие в посте из пяти.
     */
    #[Test]
    public function a_weekday_without_a_date_when_days_do_not_repeat(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);

        $caption = $this->caption();

        $this->assertMatchesRegularExpression('/\b(пн|вт|ср|чт|пт|сб|вс) \d{2}:\d{2}/u', $caption,
            'день недели и время без числа');
        $this->assertStringNotContainsString('сентября,', $caption, 'числа в строке фактов нет');
    }

    /**
     * Повторился день недели — печатаем число у ВСЕХ строк.
     *
     * Окно тематической рубрики восьмидневное: оно считается от момента
     * публикации, а не от полуночи, и «вт» в нём встречается дважды. Читатель
     * придёт не в тот день.
     */
    #[Test]
    public function a_repeated_weekday_brings_the_date_back(): void
    {
        $this->onlyRubric('spektakli');

        // Два вторника — первый и восьмой день окна. Описания самые длинные:
        // отбор идёт по полноте карточки, и оба обязаны попасть в названные.
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $this->event('Спектакль вторник первый', 0, free: false, priceMin: 500, hour: 20, description: $long);
        $this->event('Спектакль вторник второй', 7, free: false, priceMin: 500, hour: 10, description: $long);
        foreach ([1, 2, 3] as $day) {
            $this->event('Спектакль день '.$day, $day, free: false, priceMin: 500);
        }

        $caption = $this->caption();

        $this->assertStringContainsString('сентября', $caption, 'число вернулось в строку фактов');
    }

    /** «Свободно», а не «бесплатно»: короче и не спорит с «по регистрации». */
    #[Test]
    public function a_free_event_is_called_svobodno(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);

        $caption = $this->caption();

        $this->assertStringContainsString('свободно', $caption);
        $this->assertStringNotContainsString('бесплатно', $caption);
    }

    /**
     * Регистрация названа в строке цены.
     *
     * Своего поля под неё нет — слово живёт только в тексте цены. Замер по
     * живой базе: 65 событий упоминают регистрацию, и все 65 в смысле «нужна».
     */
    #[Test]
    public function registration_is_named_next_to_the_price(): void
    {
        $this->onlyRubric('besplatno');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $this->event('Форум про безопасность', 1, free: true, hour: 8, description: $long,
            priceText: 'Участие бесплатное, по предварительной регистрации.');
        $this->fill('Бесплатное', free: true);

        $this->assertStringContainsString('свободно, по регистрации', $this->caption());
    }

    /** Отрицание не считается: «без регистрации» зовёт регистрироваться на пустом месте. */
    #[Test]
    public function a_denied_registration_is_not_announced(): void
    {
        $this->onlyRubric('besplatno');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $this->event('Вход без формальностей', 1, free: true, hour: 8, description: $long,
            priceText: 'Вход свободный, без регистрации.');
        $this->fill('Бесплатное', free: true);

        $this->assertStringNotContainsString('по регистрации', $this->caption());
    }

    /**
     * Пожертвование больше не остаётся без строки цены.
     *
     * Статус не 'free', сумм у таких событий нет — и цена выходила ПУСТОЙ,
     * хотя в пул «Бесплатно» они берутся наравне с бесплатными.
     */
    #[Test]
    public function a_donation_event_gets_a_price_word(): void
    {
        $this->onlyRubric('besplatno');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $this->event('Концерт по пожертвованию', 1, free: false, hour: 8, description: $long,
            priceText: 'Вход на основе пожертвования.', donation: true);
        $this->fill('Бесплатное', free: true);

        $this->assertStringContainsString('свободный взнос', $this->caption());
    }

    /**
     * У события без времени часов в строке нет.
     *
     * В start_time у таких стоит полночь, и «сб 00:00» — это не факт, а
     * артефакт: источник назвал только день.
     */
    #[Test]
    public function an_event_without_a_time_is_printed_without_a_clock(): void
    {
        $this->onlyRubric('besplatno');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $this->event('Ярмарка весь день', 1, free: true, hour: 0, description: $long,
            timePrecision: 'date');
        $this->fill('Бесплатное', free: true);

        $caption = $this->caption();

        $this->assertStringContainsString('Ярмарка весь день', $caption);
        $this->assertStringNotContainsString('00:00', $caption);
    }

    /* ──────────────── счёт: число и ссылка из одного фильтра ──────────────── */

    /**
     * Счёт в шапке и остаток в подвале.
     *
     * Раньше числа не было вовсе, и это было верно: подвал вёл на страницу без
     * фильтра по датам, где лежит вся будущая афиша. Обещание проверялось
     * первым же нажатием.
     */
    #[Test]
    public function the_header_counts_and_the_footer_names_the_rest(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);

        $caption = $this->caption();

        $this->assertStringContainsString('6 событий', $caption, 'в шапке весь счёт окна');
        $this->assertMatchesRegularExpression('/Остальные [1-9]\d* — /u', $caption, 'в подвале остаток');
        $this->assertStringContainsString('вся бесплатная афиша', $caption);
    }

    /**
     * ИНВАРИАНТ: ссылка подвала несёт ровно тот фильтр, по которому посчитано.
     *
     * Ради него готовый адрес у рубрики и заменён описанием фильтра. Пока они
     * лежали порознь, число и страница могли разъехаться молча — и именно
     * поэтому счёт из поста когда-то убрали целиком.
     */
    #[Test]
    public function the_footer_link_carries_the_very_filter_that_was_counted(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);

        $caption = $this->caption();

        $this->assertMatchesRegularExpression('/href="[^"]*[?&]free=1/u', $caption, 'фильтр рубрики в ссылке');
        $this->assertMatchesRegularExpression('/href="[^"]*date_from=/u', $caption, 'нижняя граница окна');
        $this->assertMatchesRegularExpression('/href="[^"]*date_to=/u', $caption, 'верхняя граница окна');

        // Без дат лента смотрит и назад: замер 25.09.2026 — 52 события против
        // 26 в окне. Ссылка без границ сделала бы число враньём.
        preg_match('/href="([^"]*free=1[^"]*)"/u', $caption, $m);
        $url = html_entity_decode($m[1] ?? '');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $to = Carbon::parse((string) ($query['date_to'] ?? ''));
        $this->assertSame(
            Carbon::now('Europe/Moscow')->addDays(5)->toDateString(),
            $to->toDateString(),
            'верхняя граница ссылки — окно рубрики, пять дней',
        );
    }

    /**
     * У тематической рубрики счёта нет, и это не недоделка.
     *
     * Её подвал ведёт на страницу категории, а там нет фильтра по датам:
     * число окна рядом с такой ссылкой обещало бы не тот список.
     */
    #[Test]
    public function a_themed_rubric_stays_without_a_count(): void
    {
        $this->onlyRubric('spektakli');
        $this->fill('Спектакль', free: false, priceMin: 700);

        $caption = $this->caption();

        $this->assertStringNotContainsString('Остальные', $caption);
        $this->assertDoesNotMatchRegularExpression('/· \d+ спектакл/u', $caption, 'счёта в шапке нет');
        $this->assertStringContainsString('Вся афиша спектаклей', $caption);
    }

    /**
     * Лента упала — пост всё равно выходит, просто без числа.
     *
     * Счёт это украшение, а подборка — нет: канал не должен молчать из-за
     * того, что не посчиталась строка в шапке.
     */
    #[Test]
    public function a_broken_feed_costs_the_count_but_not_the_post(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);

        $this->app->bind(\App\Services\EventService::class, function () {
            return new class extends \App\Services\EventService
            {
                public function __construct() {}

                public function listWeb(array $filters, int $perPage = 20): array
                {
                    throw new \RuntimeException('лента недоступна');
                }
            };
        });

        $caption = $this->caption();

        $this->assertStringNotContainsString('Остальные', $caption);
        $this->assertStringContainsString('Бесплатно в эти дни', $caption, 'пост собрался');
    }

    /* ──────────────── окно «выходные» ──────────────── */

    /**
     * Календарное окно: суббота и воскресенье, а не «N дней вперёд».
     *
     * Выход в пятницу, значит выходные — завтра и послезавтра. Событие
     * пятничного вечера в такую подборку попасть не должно: оно не «на
     * выходных», оно сегодня.
     */
    #[Test]
    public function the_weekend_rubric_takes_saturday_and_sunday_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00', 'Europe/Moscow')); // пятница
        $this->onlyRubric('na-vyhodnyh');

        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $this->event('Пятничный вечер', 0, free: false, priceMin: 500, hour: 20, description: $long);
        foreach ([1, 1, 1, 2, 2] as $i => $day) {
            $this->event('Выходное '.($i + 1), $day, free: false, priceMin: 500, hour: 12 + $i, description: $long);
        }

        $caption = $this->caption(Carbon::parse('2026-09-18 14:00', 'Europe/Moscow'));

        $this->assertStringNotContainsString('Пятничный вечер', $caption, 'пятница — не выходные');
        $this->assertStringContainsString('На выходных', $caption);
    }

    /**
     * Три события одной субботы в одном посте.
     *
     * Правило «одна строка на день» на двухдневном окне даёт потолок в две
     * строки — то есть рубрика физически не смогла бы назвать больше двух.
     * Поэтому у неё оно снято, и это условие её существования.
     */
    #[Test]
    public function the_weekend_rubric_names_several_events_of_one_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00', 'Europe/Moscow'));
        $this->onlyRubric('na-vyhodnyh');

        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        foreach ([1, 1, 1, 2, 2] as $i => $day) {
            $this->event('Выходное '.($i + 1), $day, free: false, priceMin: 500, hour: 12 + $i, description: $long);
        }

        $out = app(BroadcastDigestComposer::class)
            ->compose($this->channel(), Carbon::parse('2026-09-18 14:00', 'Europe/Moscow'));

        $this->assertNotNull($out);
        $this->assertGreaterThan(2, count($out['event_ids']),
            'на двух днях названо больше двух — правило дня снято');
    }

    /** Во вторник такой рубрики нет: «на выходных» за четыре дня читают один раз. */
    #[Test]
    public function the_weekend_rubric_is_absent_on_a_tuesday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00', 'Europe/Moscow')); // вторник
        $this->onlyRubric('na-vyhodnyh');

        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        foreach ([4, 4, 4, 5, 5] as $i => $day) {
            $this->event('Выходное '.($i + 1), $day, free: false, priceMin: 500, hour: 12 + $i, description: $long);
        }

        $out = app(BroadcastDigestComposer::class)
            ->compose($this->channel(), Carbon::parse('2026-09-15 14:00', 'Europe/Moscow'));

        $this->assertNull($out, 'единственная рубрика не допущена — подборки нет');
    }

    /**
     * ИНВАРИАНТ ПРИ СБОРКЕ ЗАРАНЕЕ: счёт считается по окну ВЫХОДА, а не по «сегодня».
     *
     * Подборка собирается за сутки до слота, а то и раньше. Пока счёт брал
     * now(), подборка на выходные, собранная в четверг, считала бы одни
     * выходные, а в шапке стояли бы другие.
     */
    #[Test]
    public function the_count_follows_the_publish_window_not_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00', 'Europe/Moscow')); // пятница
        $this->onlyRubric('na-vyhodnyh');

        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        // Эти выходные — 19–20 сентября: пять событий.
        foreach ([1, 1, 1, 2, 2] as $i => $day) {
            $this->event('Ближнее '.($i + 1), $day, free: false, priceMin: 500, hour: 12 + $i, description: $long);
        }
        // Следующие — 26–27 сентября: восемь.
        foreach ([8, 8, 8, 8, 9, 9, 9, 9] as $i => $day) {
            $this->event('Дальнее '.($i + 1), $day, free: false, priceMin: 500, hour: 12 + ($i % 6), description: $long);
        }

        // Выход через неделю: считать надо ДАЛЬНИЕ выходные, не ближние.
        $caption = $this->caption(Carbon::parse('2026-09-25 14:00', 'Europe/Moscow'));

        $this->assertStringContainsString('8 событий', $caption, 'счёт по окну выхода');
        $this->assertStringNotContainsString('5 событий', $caption);
    }

    /** У рубрики с `when` ссылка короткая: даты лента разворачивает сама, той же формулой. */
    #[Test]
    public function the_weekend_footer_link_stays_short(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00', 'Europe/Moscow'));
        $this->onlyRubric('na-vyhodnyh');

        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        foreach ([1, 1, 1, 2, 2] as $i => $day) {
            $this->event('Выходное '.($i + 1), $day, free: false, priceMin: 500, hour: 12 + $i, description: $long);
        }

        $caption = $this->caption(Carbon::parse('2026-09-18 14:00', 'Europe/Moscow'));

        $this->assertMatchesRegularExpression('/href="[^"]*when=weekend/u', $caption);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*when=weekend[^"]*date_from=/u', $caption,
            'даты в такой ссылке лишние — лента считает их сама');
    }

    /* ──────────────── бюджет фразы ──────────────── */

    /**
     * Сколько места осталось на фразу — считает api и кладёт в мету.
     *
     * Порог в парсере один на все рубрики, а места у трёх строк и у пяти
     * отличается вдвое: замер 25.09.2026 — при трёх названных на фразу
     * остаётся около 200 знаков, при пяти всего около 100, а модель пишет в
     * среднем 101. Без бюджета пятая строка теряла бы фразу просто потому,
     * что порог не знал про число строк.
     */
    #[Test]
    public function the_hook_budget_shrinks_as_the_roster_grows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00', 'Europe/Moscow')); // пятница
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);

        $this->onlyRubric('na-vyhodnyh');
        foreach ([1, 1, 1, 2, 2] as $i => $day) {
            $this->event('Выходное '.($i + 1), $day, free: false, priceMin: 500, hour: 12 + $i, description: $long);
        }

        $wide = app(BroadcastDigestComposer::class)
            ->compose($this->channel(), Carbon::parse('2026-09-18 14:00', 'Europe/Moscow'));

        $this->assertNotNull($wide);
        $this->assertGreaterThan(3, count($wide['event_ids']), 'рубрика назвала больше трёх');
        $this->assertGreaterThan(0, $wide['hook_budget'], 'бюджет посчитан');

        // Та же лента, но рубрика на три строки: на фразу остаётся заметно больше.
        $this->onlyRubric('spektakli');
        foreach ([1, 2, 3, 4, 5] as $i => $day) {
            $this->event('Спектакль '.($i + 1), $day, free: false, priceMin: 700, description: $long);
        }

        $narrow = app(BroadcastDigestComposer::class)
            ->compose($this->channel(), Carbon::parse('2026-09-18 14:00', 'Europe/Moscow'));

        $this->assertNotNull($narrow);
        $this->assertGreaterThan(
            $wide['hook_budget'],
            $narrow['hook_budget'],
            'на трёх строках места на фразу больше, чем на пяти',
        );
    }

    /** Бюджет доезжает до меты — по ней парсер и пишет. */
    #[Test]
    public function the_budget_reaches_the_meta(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);

        $channel = $this->channel();
        $draft = app(BroadcastDigestComposer::class)->compose($channel, Carbon::now());
        $this->assertNotNull($draft);

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $channel->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = Carbon::now()->addDay();
        $item->save();

        app(\App\Services\Telegram\TelegramChatBroadcastService::class)->applyDigestDraft($item, $draft);

        $meta = (array) $item->fresh()->digest_meta;
        $this->assertArrayHasKey('hook_budget', $meta);
        $this->assertGreaterThan(40, (int) $meta['hook_budget']);
    }

    /* ──────────────── форма шапки ──────────────── */

    /**
     * Со счётом шапка — одно предложение с городом, без срока.
     *
     * «На выходных» и «в эти дни» уже сказали когда; «26–27 сентября» рядом с
     * ними это та же мысль во второй раз. Точка в конце нужна: следом встык
     * идёт подводка.
     */
    #[Test]
    public function a_counted_header_is_one_sentence_with_the_city(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00', 'Europe/Moscow')); // пятница
        $this->onlyRubric('na-vyhodnyh');

        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        foreach ([1, 1, 1, 2, 2] as $i => $day) {
            $this->event('Выходное '.($i + 1), $day, free: false, priceMin: 500, hour: 12 + $i, description: $long);
        }

        $head = explode("\n", $this->caption(Carbon::parse('2026-09-18 14:00', 'Europe/Moscow')))[0];

        $this->assertStringContainsString('в Воронеже', $head, 'город в предложном падеже');
        $this->assertMatchesRegularExpression('/\d+ событи\w*\.<\/b>/u', $head, 'счёт внутри предложения');
        $this->assertStringNotContainsString('·', $head, 'срока в такой шапке нет');
    }

    /** У рубрики, чей заголовок кончается временем, города в шапке нет. */
    #[Test]
    public function a_headline_ending_in_a_time_phrase_skips_the_city(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);

        $head = explode("\n", $this->caption())[0];

        $this->assertStringContainsString('Бесплатно в эти дни — ', $head);
        $this->assertStringNotContainsString('в Воронеже', $head, 'два «в» подряд не пишем');
    }

    /** Без счёта шапка прежняя: заголовок и срок через точку. */
    #[Test]
    public function an_uncounted_header_keeps_the_date_range(): void
    {
        $this->onlyRubric('spektakli');
        $this->fill('Спектакль', free: false, priceMin: 700);

        $head = explode("\n", $this->caption())[0];

        $this->assertStringContainsString('·', $head, 'срок на месте');
        $this->assertStringContainsString('сентября', $head);
        $this->assertStringNotContainsString('в Воронеже', $head, 'город только там, где есть счёт');
    }

    /**
     * Строка фактов без курсива.
     *
     * По длине он не стоит ничего — теги в подпись не считаются. Дело в виде:
     * пять наклонных строк подряд читаются как сноски, а не как факты.
     */
    #[Test]
    public function the_facts_line_is_not_italic(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);

        $this->assertStringNotContainsString('<i>', $this->caption());
    }

    /**
     * Кнопка «вне очереди» создаёт ЧЕРНОВИК, а не отправку.
     *
     * Раньше пост сразу вставал на отправку через шесть минут, и форма
     * открывалась поверх уже уехавшего.
     */
    #[Test]
    public function an_off_grid_digest_does_not_send_by_itself(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);
        $channel = $this->channel();
        $this->actingAsSuperadmin();

        $this->postJson("/api/admin/broadcast/channels/{$channel->id}/digest-now")->assertCreated();

        $item = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $channel->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->firstOrFail();

        $this->assertNull($item->publish_at);
        $this->assertNotNull($item->caption, 'состав и подпись всё равно собраны');

        $due = app(\App\Services\Telegram\TelegramChatBroadcastService::class)
            ->collectDueSingleRuns(Carbon::now()->addHours(2));

        $this->assertNotContains(
            (int) $item->id,
            array_map(static fn ($t) => (int) ($t['item_id'] ?? 0), $due),
            'черновик не уходит сам даже через два часа',
        );
    }

    /** Слота он тоже не ждёт: вне сетки — значит слот не занимает вовсе. */
    #[Test]
    public function an_off_grid_draft_is_not_given_a_free_slot(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);
        $channel = $this->channel();
        $this->actingAsSuperadmin();

        $this->postJson("/api/admin/broadcast/channels/{$channel->id}/digest-now")->assertCreated();

        app(\App\Services\Telegram\TelegramChatBroadcastService::class)
            ->fillFeedDays($channel->fresh(), Carbon::now());

        $item = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $channel->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->where('is_off_grid', true)
            ->firstOrFail();

        $this->assertNull($item->publish_at);
    }

    /** «от 0 ₽» читается как сбой: у такой цены значит только верх. */
    #[Test]
    public function a_range_starting_at_zero_names_its_ceiling(): void
    {
        $this->onlyRubric('spektakli');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);

        $id = $this->event('Вечер премьер', 1, free: false, priceMin: 0, description: $long);
        DB::table('events')->where('id', $id)->update(['price_max' => 4000, 'price_status' => 'range']);
        foreach ([2, 3, 4, 5] as $day) {
            $this->event('Спектакль '.$day, $day, free: false, priceMin: 700);
        }

        $caption = $this->caption();

        $this->assertStringNotContainsString('от 0', $caption);
        $this->assertStringContainsString('до 4000', $caption);
    }

    /* ──────────────── сеансы в одной строке ──────────────── */

    /**
     * Прошедший сеанс в строку не попадает.
     *
     * У недельного окна нижней границы нет, и склейка тянула прошлое:
     * в живом посте 28.09 стояло «сб 12 сентября и сб 3 октября».
     */
    #[Test]
    public function a_past_session_is_not_merged_in(): void
    {
        $this->onlyRubric('besplatno');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $group = $this->group();

        $past = $this->event('Серия', -14, free: true, hour: 18, description: $long, groupId: $group);
        DB::table('events')->where('id', $past)->update(['status' => 'active']);
        $this->event('Серия', 2, free: true, hour: 18, description: $long, groupId: $group);
        $this->fill('Бесплатное', free: true);

        $caption = $this->caption();

        $this->assertStringNotContainsString(' и ', explode("\n", $caption)[3] ?? '',
            'прошедший сеанс в строке не склеен');
    }

    /** Одно время, разные дни: «сб и вс с 12:00». */
    #[Test]
    public function one_time_on_two_days_merges(): void
    {
        $this->onlyRubric('besplatno');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $group = $this->group();

        $this->event('Зин-фестиваль', 1, free: true, hour: 12, description: $long, groupId: $group);
        $this->event('Зин-фестиваль', 2, free: true, hour: 12, description: $long, groupId: $group);
        $this->fill('Бесплатное', free: true);

        $this->assertMatchesRegularExpression('/(пн|вт|ср|чт|пт|сб|вс) и (пн|вт|ср|чт|пт|сб|вс) с 12:00/u', $this->caption());
    }

    /** Один день, разное время: «вс 11:30 и 14:30». */
    #[Test]
    public function two_times_on_one_day_merge(): void
    {
        $this->onlyRubric('besplatno');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $group = $this->group();

        $this->event('Раскоп', 1, free: true, hour: 11, description: $long, groupId: $group, minute: 30);
        $this->event('Раскоп', 1, free: true, hour: 14, description: $long, groupId: $group, minute: 30);
        $this->fill('Бесплатное', free: true);

        $this->assertMatchesRegularExpression('/(пн|вт|ср|чт|пт|сб|вс) 11:30 и 14:30/u', $this->caption());
    }

    /** Разные дни И разное время короткой формы не имеют — печатаем один сеанс. */
    #[Test]
    public function a_mixed_series_is_not_merged(): void
    {
        $this->onlyRubric('besplatno');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $group = $this->group();

        $this->event('Смешанная серия', 1, free: true, hour: 11, description: $long, groupId: $group);
        $this->event('Смешанная серия', 2, free: true, hour: 19, description: $long, groupId: $group);
        $this->fill('Бесплатное', free: true);

        $caption = $this->caption();

        $this->assertStringNotContainsString(' и 19:00', $caption);
        $this->assertStringNotContainsString(' с 11:00', $caption);
    }

    /** Длинная серия в строку не лезет: перечисление становится шумом. */
    #[Test]
    public function a_long_series_falls_back_to_one_session(): void
    {
        $this->onlyRubric('besplatno');
        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        $group = $this->group();

        foreach ([9, 12, 15, 18] as $hour) {
            $this->event('Каждый час', 1, free: true, hour: $hour, description: $long, groupId: $group);
        }
        $this->fill('Бесплатное', free: true);

        $this->assertStringNotContainsString('12:00 и', $this->caption());
    }

    private function group(): int
    {
        $community = \App\Models\Community::create([
            'name' => 'Серия '.uniqid(),
            'city_id' => $this->cityId,
        ]);

        return (int) DB::table('event_groups')->insertGetId([
            'group_key' => 'grp-'.uniqid(),
            'city_id' => $this->cityId,
            'community_id' => $community->id,
            'title_norm' => 'seriya-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ──────────────── рубрику выбирает человек ──────────────── */

    /** Названная рубрика побеждает ротацию. */
    #[Test]
    public function a_named_rubric_wins_over_the_rotation(): void
    {
        $this->onlyRubric('besplatno', 'spektakli');
        $this->fill('Бесплатное', free: true);
        foreach ([1, 2, 3, 4, 5] as $day) {
            $this->event('Спектакль '.$day, $day, free: false, priceMin: 700);
        }

        $forced = app(BroadcastDigestComposer::class)
            ->compose($this->channel(), Carbon::now(), null, 'spektakli');

        $this->assertNotNull($forced);
        $this->assertSame('spektakli', $forced['theme_slug']);
    }

    /** Не набралась — отказ, а не подмена другой. */
    #[Test]
    public function a_named_rubric_that_gathers_nothing_returns_null(): void
    {
        $this->onlyRubric('besplatno', 'vecherom');
        $this->fill('Бесплатное', free: true);

        $this->assertNull(
            app(BroadcastDigestComposer::class)
                ->compose($this->channel(), Carbon::now(), null, 'vecherom'),
        );
    }

    /** У выбранной руками ограничение по дню выхода не действует. */
    #[Test]
    public function a_named_rubric_ignores_its_weekday_limit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00', 'Europe/Moscow')); // вторник
        $this->onlyRubric('na-vyhodnyh');

        $long = str_repeat('описание события достаточной длины и подробностей. ', 8);
        foreach ([4, 4, 4, 5, 5] as $i => $day) {
            $this->event('Выходное '.($i + 1), $day, free: false, priceMin: 500, hour: 12 + $i, description: $long);
        }

        $at = Carbon::parse('2026-09-15 14:00', 'Europe/Moscow');
        $composer = app(BroadcastDigestComposer::class);

        $this->assertNull($composer->compose($this->channel(), $at), 'сама во вторник не выходит');
        $this->assertNotNull($composer->compose($this->channel(), $at, null, 'na-vyhodnyh'), 'а по просьбе — да');
    }

    /** Справочник говорит, что наберётся, а что нет. */
    #[Test]
    public function the_theme_list_says_what_gathers(): void
    {
        $this->onlyRubric('besplatno', 'vecherom');
        $this->fill('Бесплатное', free: true);
        $this->actingAsSuperadmin();

        $rows = $this->getJson("/api/admin/broadcast/channels/{$this->channel()->id}/digest-themes")
            ->assertOk()->json('data');

        $by = collect($rows)->keyBy('slug');
        $this->assertTrue($by['besplatno']['gathers']);
        $this->assertGreaterThan(0, $by['besplatno']['named']);
        $this->assertFalse($by['vecherom']['gathers']);
    }

    /* ──────────────── значок у строки ──────────────── */

    /**
     * Подавление «тема названа словом» в подборке ВЫКЛЮЧЕНО.
     *
     * Заголовки фикстуры начинаются со слова «Спектакль» — ровно тот случай,
     * когда пост события значок снимает. В подборке он образует столбец слева
     * от пяти строк, и дыра в нём читается как сбой вёрстки: замер 25.09.2026
     * — с подавлением значка нет у 167 событий из 465, без него у 12.
     */
    #[Test]
    public function a_line_keeps_its_emoji_even_when_the_title_says_the_theme(): void
    {
        // Смешанная рубрика: в тематической значка у строки нет вовсе —
        // столбец из одинаковых ничего не сообщает.
        $this->onlyRubric('besplatno');
        $this->fill('Спектакль', free: true);

        $caption = $this->caption();

        $this->assertMatchesRegularExpression('/\x{1F3AD} <b><a /u', $caption,
            'значок театра стоит перед названием, хотя «Спектакль» есть в заголовке');
    }

    /** В подборке одной темы значка у строки нет: столбец был бы из одинаковых. */
    #[Test]
    public function a_themed_digest_has_no_per_line_emoji(): void
    {
        $this->onlyRubric('spektakli');
        $this->fill('Спектакль', free: false, priceMin: 700);

        $this->assertDoesNotMatchRegularExpression('/\x{1F3AD} <b><a /u', $this->caption());
    }

    /**
     * Рука сильнее карты.
     *
     * Карта угадывает тему, но не замысел: у археологического раскопа в ней
     * экскурсия, а не лопата.
     */
    #[Test]
    public function a_hand_picked_emoji_wins_over_the_map(): void
    {
        $this->onlyRubric('besplatno');
        $this->fill('Бесплатное', free: true);

        $channel = $this->channel();
        $draft = app(BroadcastDigestComposer::class)->compose($channel, Carbon::now());
        $this->assertNotNull($draft);

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $channel->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = Carbon::now()->addDay();
        $item->save();

        app(\App\Services\Telegram\TelegramChatBroadcastService::class)->applyDigestDraft($item, $draft);

        $first = (int) $draft['event_ids'][0];
        $meta = (array) $item->fresh()->digest_meta;
        $meta['emoji'] = [(string) $first => '⛏️'];
        $item->digest_meta = $meta;
        $item->save();

        $again = app(BroadcastDigestComposer::class)->recompose($item->fresh(), $channel, Carbon::now());

        $this->assertNotNull($again);
        $this->assertStringContainsString('⛏️ <b><a ', $again['caption'], 'поставленный рукой значок в посте');
    }

    /** Подпись собранной подборки — тем же путём, что и в жизни. */
    private function caption(?Carbon $at = null): string
    {
        $out = app(BroadcastDigestComposer::class)->compose($this->channel(), $at ?? Carbon::now());
        $this->assertNotNull($out, 'подборка обязана собраться');

        return (string) $out['caption'];
    }

    private function actingAsSuperadmin(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);
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

    /**
     * Оставить в конфиге только названные рубрики.
     *
     * Фильтруем от ИСХОДНОГО списка, а не от текущего: второй вызов в одном
     * тесте иначе фильтрует уже отфильтрованное и оставляет пусто — подборка
     * молча перестаёт собираться, и падает не та строка, где ошибка.
     */
    private function onlyRubric(string ...$slugs): void
    {
        $this->allThemes ??= (array) config('broadcast_digest.themes', []);

        config(['broadcast_digest.themes' => array_values(array_filter(
            $this->allThemes,
            static fn (array $t) => in_array($t['slug'] ?? '', $slugs, true),
        ))]);
    }

    /** @var list<array<string, mixed>>|null */
    private ?array $allThemes = null;

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
        ?string $priceText = null,
        string $timePrecision = 'datetime',
        bool $donation = false,
        ?int $groupId = null,
        int $minute = 0,
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
        $at = Carbon::now('Europe/Moscow')->addDays($dayOffset)->setTime($hour, $minute)->utc();

        $event->start_time = $at;
        $event->start_date = $at->toDateString();
        $event->end_time = $at->copy()->addHours(2);
        $event->description = $description ?? str_repeat('описание события достаточной длины. ', 5);
        $event->price_status = $donation
            ? 'donation'
            : ($free ? 'free' : ($priceMin === null ? 'unknown' : 'range'));
        $event->price_min = $free || $donation ? 0 : $priceMin;
        $event->price_text = $priceText;
        $event->event_group_id = $groupId;
        $event->time_precision = $timePrecision;
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
            ['Воронеж', 'RU', 39.2, 51.66, 'active', 'voronezh', now(), now()]
        );

        return (int) DB::table('cities')->where('slug', 'voronezh')->value('id');
    }
}
