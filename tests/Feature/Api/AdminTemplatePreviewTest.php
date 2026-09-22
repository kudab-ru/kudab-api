<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Демо шаблона показывает РАЗНИЦУ между формами.
 *
 * Владелец 22.09.2026: «basic и lead-below почему-то одинаковые». Так и было:
 * демо собиралось на ближайшем событии, а у ближайших часто нет живой фразы —
 * квесты и билетные карточки приходят с одним пресс-релизом. Пресс-релиз во
 * всех формах стоит после сведений, поэтому предпросмотр выглядел одинаковым,
 * сколько шаблон ни переключай, и казался сломанным.
 *
 * Формы отличаются прежде всего тем, ГДЕ стоит фраза модели — значит, и
 * событие для демо нужно с фразой.
 */
class AdminTemplatePreviewTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);

        DB::insert(
            "INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES ('Воронеж','RU', ST_SetSRID(ST_Point(39.2,51.66),4326),'active','voronezh', now(), now())"
        );
        $this->cityId = (int) City::query()->where('slug', 'voronezh')->value('id');
    }

    private function event(string $title, int $inDays, ?string $lead): Event
    {
        $community = Community::create(['name' => $title.' орг', 'city_id' => $this->cityId]);

        $e = new Event;
        $e->community_id = $community->id;
        $e->title = $title;
        $e->status = 'active';
        $e->city_id = $this->cityId;
        $e->address = 'г Воронеж, ул Мира, д 1';
        $e->start_time = Carbon::now()->addDays($inDays)->setTime(19, 0);
        $e->start_date = $e->start_time->toDateString();
        $e->description = 'Пресс-релиз из источника.';
        $e->tg_description = $lead;
        $e->save();

        return $e->fresh();
    }

    private function preview(string $body): array
    {
        return $this->postJson('/api/admin/broadcast/templates/preview', ['body' => $body])
            ->assertOk()->json('data');
    }

    /** Ради этого всё: демо берёт событие с фразой, даже если оно не ближайшее. */
    public function test_preview_prefers_event_with_a_live_phrase(): void
    {
        $this->event('Квест без фразы', 1, null);
        $this->event('Спектакль с фразой', 5, 'Бим ждёт и верит, пока люди решают свои дела.');

        $data = $this->preview('🎟 {title}\n{lead}');

        $this->assertSame('Спектакль с фразой', $data['event']['title']);
    }

    /** И тогда две формы дают РАЗНЫЙ текст — иначе предпросмотр бесполезен. */
    public function test_two_forms_render_differently(): void
    {
        $this->event('Спектакль с фразой', 2, 'Бим ждёт и верит.');

        $top = $this->preview("🎟 <b>{title}</b>\n{lead}\n\n📍 {address}\n🗓 {start_time|human}");
        $below = $this->preview("🎟 <b>{title}</b>\n\n📍 {address}\n🗓 {start_time|human}\n\n{lead}");

        $this->assertNotSame($top['text'], $below['text']);
    }

    /** Фразы нет ни у кого — демо всё равно собирается, а не падает. */
    public function test_falls_back_when_nobody_has_a_phrase(): void
    {
        $this->event('Квест без фразы', 1, null);

        $data = $this->preview('🎟 {title}');

        $this->assertSame('Квест без фразы', $data['event']['title']);
    }

    /** Явно выбранное событие важнее правила: его и показываем. */
    public function test_explicit_event_wins(): void
    {
        $quest = $this->event('Квест без фразы', 1, null);
        $this->event('Спектакль с фразой', 5, 'Живая фраза.');

        $data = $this->postJson('/api/admin/broadcast/templates/preview', [
            'body' => '🎟 {title}',
            'event_id' => $quest->id,
        ])->assertOk()->json('data');

        $this->assertSame('Квест без фразы', $data['event']['title']);
    }
}
