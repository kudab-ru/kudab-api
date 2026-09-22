<?php

namespace Tests\Feature\Telegram;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Services\Telegram\EventCaptionBuilder;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\TelegramMessageTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Текст поста: строка места и порядок абзацев.
 *
 * Оба сюжета про одно — что подписчик читает первым. До этих правок он видел
 * «📍 Воронеж, 54, 1 этаж» и находил живую фразу пятой строкой.
 *
 * Шаблоны берём СИДЕРОМ, а не своей строкой: тест обязан проверять тот текст,
 * который поедет в канал, иначе он подтверждает выдумку. Ровно так уже
 * случилось с тестом скорера — он подставлял несуществующий статус цены.
 */
class EventCaptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TelegramMessageTemplatesSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-15 06:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function builder(): EventCaptionBuilder
    {
        return app(EventCaptionBuilder::class);
    }

    // ------------------------------------------------------------------
    // Строка места
    // ------------------------------------------------------------------

    public function test_venue_name_replaces_the_address_tail(): void
    {
        $event = $this->makeEvent(
            venueName: 'Марьяж',
            address: '394036, Воронежская обл, г Воронеж, пр-кт Революции, д 54, 1 этаж',
        );

        $caption = $this->builder()->build($event, 'basic', $this->asOf());

        $this->assertStringContainsString('📍 Воронеж, Марьяж', $caption);
        $this->assertStringNotContainsString('1 этаж', $caption, 'хвост адреса уступил имя место');
    }

    /** Имя чистится тем же правилом, что в подборке: город через трубу — не часть имени. */
    public function test_venue_name_is_cleaned_of_source_garbage(): void
    {
        $event = $this->makeEvent(venueName: 'Новый театр | Воронеж', address: 'г Воронеж, ул Мира, д 1');

        $this->assertStringContainsString(
            '📍 Воронеж, Новый театр',
            $this->builder()->build($event, 'basic', $this->asOf()),
        );
    }

    /**
     * Амперсанд в имени площадки не ломает пост.
     *
     * В окне ленты живёт «JUST Bar&Kitchen». Строка места не экранировалась
     * никогда — и не ломала ничего лишь потому, что имени площадки в ней не
     * было. С именем без экранирования Telegram отклонил бы ВЕСЬ пост.
     */
    public function test_ampersand_in_venue_name_is_escaped(): void
    {
        $event = $this->makeEvent(venueName: 'JUST Bar&Kitchen', address: 'г Воронеж, ул Мира, д 1');

        $caption = $this->builder()->build($event, 'basic', $this->asOf());

        $this->assertStringContainsString('📍 Воронеж, JUST Bar&amp;Kitchen', $caption);
        $this->assertStringNotContainsString('Bar&K', $caption);
    }

    /** Нет площадки — строка та же, что была: город и хвост адреса. */
    public function test_without_venue_the_address_tail_stays(): void
    {
        $event = $this->makeEvent(venueName: null, address: 'г Воронеж, ул Мира, д 1');

        $this->assertStringContainsString(
            '📍 Воронеж, ул Мира, д 1',
            $this->builder()->build($event, 'basic', $this->asOf()),
        );
    }

    /**
     * Город из строки не пропадает — на нём держится «это за городом».
     *
     * Каждый седьмой пост канала — поездка за 30–80 км, и в поле city у таких
     * событий стоит Рамонь или Костёнки, а не Воронеж.
     */
    public function test_locality_outside_the_city_stays_visible(): void
    {
        $event = $this->makeEvent(
            venueName: 'Дворцовый комплекс Ольденбургских',
            address: 'Воронежская обл, рп Рамонь, ул Школьная, д 23',
            city: 'Рамонь',
        );

        $this->assertStringContainsString(
            '📍 Рамонь, Дворцовый комплекс Ольденбургских',
            $this->builder()->build($event, 'basic', $this->asOf()),
        );
    }

    // ------------------------------------------------------------------
    // Порядок абзацев
    // ------------------------------------------------------------------

    public function test_model_lead_stands_right_under_the_title(): void
    {
        $event = $this->makeEvent(
            venueName: 'Марьяж',
            address: 'г Воронеж, ул Мира, д 1',
            tgDescription: 'Фатальное танго и молитва — всё в один вечер.',
            description: 'Приглашаем вас на концерт камерного трио. Начало в 19:00.',
        );

        $lines = explode("\n", $this->builder()->build($event, 'basic', $this->asOf()));

        // 🎟 из первой строки убран: билет к содержанию отношения не имеет и
        // стоял у каждого поста одинаково. Вместо него значок по теме.
        $this->assertStringContainsString('<b>Концерт «Лики эпохи»</b>', $lines[0]);
        $this->assertStringNotContainsString('🎟', $lines[0]);
        // Под названием пустая строка: название это заголовок, и оно получает
        // воздух. Все три формы теперь открываются одинаково.
        $this->assertSame('', $lines[1]);
        $this->assertSame('Фатальное танго и молитва — всё в один вечер.', $lines[2]);
    }

    /**
     * Анонса нет — наверх поднимается пресс-релиз.
     *
     * ПРАВИЛО ПЕРЕВЁРНУТО НАМЕРЕННО. Раньше здесь стояло «наверх не
     * поднимается НИЧЕГО»: считалось, что пресс-релиз начинается с «Приглашаем
     * вас на…» и крючка из него не выйдет. Посылку проверили на живых данных —
     * из 432 предстоящих событий с описанием и без фразы модели дежурным
     * оборотом начинаются 32, то есть 7%. Остальные 93% открываются по делу:
     * «Готэм в панике. Бэтмен исчез три дня назад…».
     *
     * Цена старого правила была высокой: фраза модели есть у 15 событий из
     * 449, поэтому «текст сверху» и «текст снизу» давали ОДИН И ТОТ ЖЕ пост у
     * 434 из них, и чередование форм по дням крутило одно и то же.
     */
    public function test_press_release_rises_when_there_is_no_model_phrase(): void
    {
        $event = $this->makeEvent(
            venueName: 'Марьяж',
            address: 'г Воронеж, ул Мира, д 1',
            tgDescription: null,
            description: 'Приглашаем вас на ток-шоу с участием известных гостей.',
        );

        $lines = explode("\n", $this->builder()->build($event, 'basic', $this->asOf()));

        $this->assertStringContainsString('<b>Концерт «Лики эпохи»</b>', $lines[0]);
        $this->assertSame('', $lines[1]);
        $this->assertSame('Приглашаем вас на ток-шоу с участием известных гостей.', $lines[2]);
    }

    /**
     * Формы «текст сверху» и «текст снизу» обязаны различаться на ОБЫЧНОМ
     * событии — без фразы модели. Ради этого всё и переписывалось.
     */
    public function test_two_forms_differ_without_a_model_phrase(): void
    {
        $event = $this->makeEvent(
            venueName: 'Марьяж',
            address: 'г Воронеж, ул Мира, д 1',
            tgDescription: null,
            description: 'Приглашаем вас на ток-шоу с участием известных гостей.',
        );

        $above = $this->builder()->build($event, 'basic', $this->asOf());
        $below = $this->builder()->build($event, 'lead-below', $this->asOf());

        $this->assertNotSame($above, $below);
        $this->assertStringContainsString('ток-шоу', $above);
        $this->assertStringContainsString('ток-шоу', $below);
    }

    /**
     * Цитата без фразы модели обязана нести описание источника, а не остаться
     * пустой полоской. Именно пустую цитату и получали 97% постов.
     */
    public function test_quote_is_never_empty_when_there_is_any_text(): void
    {
        $event = $this->makeEvent(
            venueName: 'Марьяж',
            address: 'г Воронеж, ул Мира, д 1',
            tgDescription: null,
            description: 'Приглашаем вас на ток-шоу с участием известных гостей.',
        );

        $caption = $this->builder()->build($event, 'quote', $this->asOf());

        $this->assertStringNotContainsString('<blockquote></blockquote>', $caption);
        $this->assertStringContainsString('<blockquote>Приглашаем вас на ток-шоу', $caption);
    }

    /** Текста нет вовсе — цитаты нет тоже, пустой полоски не остаётся. */
    public function test_quote_disappears_without_any_text(): void
    {
        $event = $this->makeEvent(
            venueName: 'Марьяж',
            address: 'г Воронеж, ул Мира, д 1',
            tgDescription: null,
            description: null,
        );

        $caption = $this->builder()->build($event, 'quote', $this->asOf());

        $this->assertStringNotContainsString('blockquote', $caption);
    }

    /**
     * Пустой плейсхолдер не оставляет после себя висячий пробел.
     *
     * Тело шаблона — «<b>{title}</b> {kind_emoji}». Значок молчит, когда тема
     * уже названа словом в заголовке, и строка оканчивалась пробелом после
     * закрывающего тега. Невидимо в коде, видно в клиенте.
     */
    public function test_empty_placeholder_leaves_no_trailing_space(): void
    {
        $event = $this->makeEvent(
            venueName: 'Марьяж',
            address: 'г Воронеж, ул Мира, д 1',
            tgDescription: 'Фатальное танго и молитва — всё в один вечер.',
        );

        $caption = $this->builder()->build($event, 'basic', $this->asOf());

        foreach (explode("\n", $caption) as $line) {
            $this->assertSame(rtrim($line), $line, "строка оканчивается пробелом: [$line]");
        }
    }

    /** Анонс модели печатается ОДИН раз, а не дважды. */
    public function test_lead_is_not_printed_twice(): void
    {
        $lead = 'Фатальное танго и молитва — всё в один вечер.';
        $event = $this->makeEvent(
            venueName: 'Марьяж',
            address: 'г Воронеж, ул Мира, д 1',
            tgDescription: $lead,
            description: 'Приглашаем вас на концерт камерного трио.',
        );

        $caption = $this->builder()->build($event, 'basic', $this->asOf());

        $this->assertSame(1, mb_substr_count($caption, $lead));
        $this->assertStringNotContainsString(
            'Приглашаем вас',
            $caption,
            'при живой фразе пресс-релиз молчит — иначе пост говорит одно и то же дважды',
        );
    }

    /** Строки тегов в посте нет: ключ пуст всегда, и строка убрана из шаблона. */
    public function test_tag_line_is_gone(): void
    {
        $caption = $this->builder()->build(
            $this->makeEvent(venueName: 'Марьяж', address: 'г Воронеж, ул Мира, д 1'),
            'basic',
            $this->asOf(),
        );

        $this->assertStringNotContainsString('🏷', $caption);
    }

    // ------------------------------------------------------------------

    private function asOf(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-15 06:00:00', 'Europe/Moscow');
    }

    // ------------------------------------------------------------------
    // Цена со ссылкой: где она встаёт в разных формах поста
    // ------------------------------------------------------------------

    private function paidEvent(): Event
    {
        return $this->makeEvent(
            tgDescription: 'Бим ждёт и верит, пока люди решают свои дела.',
            priceStatus: 'external',
            priceUrl: 'https://tickets.example/bim',
            priceText: 'Билеты уже в продаже',
        );
    }

    /** Цена отдельной строкой — как в нынешних шаблонах. */
    public function test_price_line_becomes_a_link(): void
    {
        $body = "🎟 <b>{title}</b>\n{lead}\n\n📍 {address}\n🗓 {start_time|human}\n💸 {price_label}";

        $caption = $this->builder()->buildWithBody($this->paidEvent(), $body, $this->asOf());

        $this->assertStringContainsString('💸 <a href="https://tickets.example/bim">Билеты уже в продаже</a>', $caption);
    }

    /**
     * Цена ВНУТРИ строки — форма с заходом фразой, где место, время и цена
     * идут одной строкой через точку.
     *
     * Раньше ссылка не подставлялась, а дописывалась отдельной строкой В САМОМ
     * КОНЦЕ, после ссылок на сайт: форма выглядела сломанной, и завести её было
     * нельзя. Проверено на живом событии 22.09.2026.
     */
    public function test_inline_price_stays_in_place(): void
    {
        $body = "{lead}\n\n🎟 <b>{title}</b>\n📍 {address} · 🗓 {start_time|human} · 💸 {price_label}";

        $caption = $this->builder()->buildWithBody($this->paidEvent(), $body, $this->asOf());
        $lines = explode("\n", $caption);

        $this->assertStringContainsString('💸 <a href="https://tickets.example/bim">', $caption);
        // Ссылка осталась в своей строке, а не уехала в хвост.
        $this->assertStringContainsString('📍', $lines[3] ?? '');
        $this->assertStringContainsString('tickets.example', $lines[3] ?? '');
    }

    /** Эмодзи не дублируется: в шаблоне оно уже стоит перед подписью. */
    public function test_inline_price_does_not_double_the_emoji(): void
    {
        $body = "🎟 <b>{title}</b>\n📍 {address} · 💸 {price_label}";

        $caption = $this->builder()->buildWithBody($this->paidEvent(), $body, $this->asOf());

        $this->assertSame(1, mb_substr_count($caption, '💸'));
    }

    /** Подписи цены в шаблоне нет вовсе — ссылка всё равно не теряется. */
    public function test_price_link_is_not_lost_when_template_has_no_price(): void
    {
        $body = "🎟 <b>{title}</b>\n📍 {address}";

        $caption = $this->builder()->buildWithBody($this->paidEvent(), $body, $this->asOf());

        $this->assertStringContainsString('tickets.example', $caption);
    }

    private function makeEvent(
        ?string $venueName = null,
        string $address = 'г Воронеж, ул Мира, д 1',
        string $city = 'Воронеж',
        ?string $tgDescription = null,
        ?string $description = null,
        ?string $priceStatus = null,
        ?string $priceUrl = null,
        ?string $priceText = null,
    ): Event {
        $cityRow = City::query()->where('slug', 'voronezh')->first();
        if ($cityRow === null) {
            // Через сырой SQL: у cities колонка location NOT NULL и типа
            // geography, Eloquent такую не заполнит.
            DB::insert(
                'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
                 VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
                ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh', now(), now()],
            );
            $cityRow = City::query()->where('slug', 'voronezh')->firstOrFail();
        }

        $venueId = null;
        if ($venueName !== null) {
            $venueId = (int) DB::table('venues')->insertGetId([
                'city_id' => $cityRow->id,
                'name' => $venueName,
                'slug' => 'venue-'.uniqid(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $community = Community::create(['name' => 'Организатор', 'city_id' => $cityRow->id]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->title = 'Концерт «Лики эпохи»';
        $event->status = 'active';
        $event->city_id = $cityRow->id;
        $event->city = $city;
        $event->address = $address;
        $event->venue_id = $venueId;
        $event->start_time = Carbon::parse('2026-09-16 19:00', 'Europe/Moscow');
        $event->start_date = '2026-09-16';
        $event->tg_description = $tgDescription;
        $event->description = $description;
        if ($priceStatus !== null) {
            $event->price_status = $priceStatus;
            $event->price_url = $priceUrl;
            $event->price_text = $priceText;
        }
        $event->save();

        return $event->fresh();
    }
}
