<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Заявки организаторов: сторона спроса.
 *
 * До сих пор источники искали только мы сами — мониторили ссылки в постах,
 * разведывали сайты, подписывались на паблики. Этот путь слеп к мелкому:
 * маленькое сообщество ссылок на себя не оставляет. Форма на сайте была
 * написана и помечена «НЕ ПОДКЛЮЧЁН» — ручки приёма не существовало, и
 * страница «Организаторам» звала присылать ссылки в пустоту.
 *
 * Ручка публичная, поэтому проверяем и то, что она не принимает мусор.
 */
class OrganizerLeadsTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $override = []): array
    {
        return array_merge([
            'kind' => 'source',
            'source_url' => 'https://vk.com/paradice_vrn',
            'contact' => '@organizer',
            'city' => 'Воронеж',
            'comment' => 'Настолки по выходным',
            'page_path' => '/organizers',
        ], $override);
    }

    private function superadmin(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    /** Ради этого всё: организатор присылает ссылку сам, без авторизации. */
    public function test_anyone_can_send_a_lead(): void
    {
        $this->postJson('/api/web/organizer-leads', $this->lead())->assertCreated();

        $row = DB::table('organizer_leads')->first();
        $this->assertSame('https://vk.com/paradice_vrn', $row->source_url);
        $this->assertSame('@organizer', $row->contact);
        $this->assertNull($row->resolved_at);
    }

    /** Заявка «подключите источник» без ссылки бессмысленна. */
    public function test_source_lead_requires_a_link(): void
    {
        $this->postJson('/api/web/organizer-leads', $this->lead(['source_url' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_url');
    }

    /** А спонсорская — не требует: там нечего подключать. */
    public function test_sponsor_lead_needs_no_link(): void
    {
        $this->postJson('/api/web/organizer-leads', $this->lead([
            'kind' => 'sponsor', 'source_url' => null,
        ]))->assertCreated();
    }

    public function test_contact_is_required(): void
    {
        $this->postJson('/api/web/organizer-leads', $this->lead(['contact' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact');
    }

    /** Мусор в ссылке не принимаем — иначе разбирать будет нечего. */
    public function test_garbage_url_is_rejected(): void
    {
        $this->postJson('/api/web/organizer-leads', $this->lead(['source_url' => 'не ссылка']))
            ->assertStatus(422);
    }

    public function test_admin_sees_new_leads(): void
    {
        $this->postJson('/api/web/organizer-leads', $this->lead())->assertCreated();
        $this->superadmin();

        $res = $this->getJson('/api/admin/organizer-leads')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame(1, $res->json('meta.new_count'));
    }

    /** Разобранная заявка уходит из списка, но не исчезает. */
    public function test_resolved_lead_leaves_the_list(): void
    {
        $this->postJson('/api/web/organizer-leads', $this->lead())->assertCreated();
        $this->superadmin();
        $id = (int) DB::table('organizer_leads')->value('id');

        $this->postJson("/api/admin/organizer-leads/{$id}/resolve", ['resolution' => 'connected'])->assertOk();

        $this->assertSame([], $this->getJson('/api/admin/organizer-leads')->json('data'));
        $this->assertCount(1, $this->getJson('/api/admin/organizer-leads?resolved=1')->json('data'));
        $this->assertSame('connected', DB::table('organizer_leads')->value('resolution'));
    }

    public function test_resolution_value_is_checked(): void
    {
        $this->postJson('/api/web/organizer-leads', $this->lead())->assertCreated();
        $this->superadmin();
        $id = (int) DB::table('organizer_leads')->value('id');

        $this->postJson("/api/admin/organizer-leads/{$id}/resolve", ['resolution' => 'что-то'])
            ->assertStatus(422);
    }

    public function test_admin_list_requires_superadmin(): void
    {
        $this->getJson('/api/admin/organizer-leads')->assertUnauthorized();
    }
}
