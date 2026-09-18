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

        $this->assertStringContainsString('🎟', $lines[0]);
        $this->assertSame('Фатальное танго и молитва — всё в один вечер.', $lines[1]);
    }

    /**
     * Анонса нет — наверх не поднимается НИЧЕГО.
     *
     * Иначе первой строкой встало бы «Приглашаем вас на…» из пресс-релиза:
     * в июле анонс был у одного события из восьми, и правило «крючок первой
     * строкой» без этого различения сделало бы посты хуже, а не лучше.
     */
    public function test_press_release_never_rises_to_the_top(): void
    {
        $event = $this->makeEvent(
            venueName: 'Марьяж',
            address: 'г Воронеж, ул Мира, д 1',
            tgDescription: null,
            description: 'Приглашаем вас на ток-шоу с участием известных гостей.',
        );

        $caption = $this->builder()->build($event, 'basic', $this->asOf());
        $lines = explode("\n", $caption);

        $this->assertStringContainsString('🎟', $lines[0]);
        $this->assertStringContainsString('📍', $lines[2], 'между названием и местом ничего не встало');
        $this->assertStringContainsString('Приглашаем вас на ток-шоу', $caption, 'пресс-релиз остался внизу');
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

    private function makeEvent(
        ?string $venueName = null,
        string $address = 'г Воронеж, ул Мира, д 1',
        string $city = 'Воронеж',
        ?string $tgDescription = null,
        ?string $description = null,
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
        $event->save();

        return $event->fresh();
    }
}
