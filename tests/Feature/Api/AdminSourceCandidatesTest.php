<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Очередь доменов-кандидатов в источники.
 *
 * Первый шаг воронки источников: механика подключения сайтов была готова
 * целиком (probe, карантин, self-heal, онбординг), но домены искала read-only
 * команда вне расписания, и владелец нигде не видел, что стоит подключить.
 */
class AdminSourceCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    private function seedCandidate(string $domain, int $communities, ?string $verdict = null, bool $hidden = false): int
    {
        return (int) DB::table('source_candidates')->insertGetId([
            'domain' => $domain,
            'sample_url' => "https://{$domain}/afisha/1",
            'communities' => $communities,
            'posts' => $communities * 3,
            'verdict' => $verdict,
            'dismissed_at' => $hidden ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_queue_is_ranked_by_communities(): void
    {
        $this->superadmin();
        $this->seedCandidate('kremlin.ru', 17);
        $this->seedCandidate('vrn.kassir.ru', 5, 'jsonld');

        $data = $this->getJson('/api/admin/sources/profiles/candidates')->assertOk()->json('data');

        $this->assertSame(['kremlin.ru', 'vrn.kassir.ru'], array_column($data, 'domain'));
        $this->assertSame('jsonld', $data[1]['verdict']);
    }

    /** Скрытое не показываем — ради этого кнопка и нужна. */
    public function test_hidden_candidate_is_out_of_the_queue(): void
    {
        $this->superadmin();
        $this->seedCandidate('kremlin.ru', 17, null, hidden: true);
        $this->seedCandidate('vrn.kassir.ru', 5);

        $res = $this->getJson('/api/admin/sources/profiles/candidates')->assertOk();

        $this->assertSame(['vrn.kassir.ru'], array_column($res->json('data'), 'domain'));
        $this->assertSame(1, $res->json('meta.hidden_count'));
    }

    public function test_hidden_can_be_listed_explicitly(): void
    {
        $this->superadmin();
        $this->seedCandidate('kremlin.ru', 17, null, hidden: true);

        $data = $this->getJson('/api/admin/sources/profiles/candidates?hidden=1')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['dismissed']);
    }

    public function test_dismiss_and_restore(): void
    {
        $this->superadmin();
        $id = $this->seedCandidate('kremlin.ru', 17);

        $this->postJson("/api/admin/sources/profiles/candidates/{$id}/dismiss")->assertOk();
        $this->assertNotNull(DB::table('source_candidates')->where('id', $id)->value('dismissed_at'));

        $this->postJson("/api/admin/sources/profiles/candidates/{$id}/restore")->assertOk();
        $this->assertNull(DB::table('source_candidates')->where('id', $id)->value('dismissed_at'));
    }

    public function test_missing_candidate_is_404(): void
    {
        $this->superadmin();

        $this->postJson('/api/admin/sources/profiles/candidates/999/dismiss')->assertNotFound();
    }

    /** Очередь источников — суперадминская, как и сами профили. */
    public function test_requires_superadmin(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/sources/profiles/candidates')->assertForbidden();
    }
}
