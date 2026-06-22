<?php

namespace Tests\Feature\Api;

use App\Models\ErrorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Просмотрщик error_logs в админке: гейт, фильтры (по умолчанию нерешённые,
 * include_resolved, type, q), сводка по типам, пометка «решено».
 */
class AdminErrorLogsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function seedLog(string $type, bool $resolved, string $text = 'boom'): ErrorLog
    {
        return ErrorLog::create([
            'type' => $type,
            'error_text' => $text,
            'logged_at' => now(),
            'resolved' => $resolved,
        ]);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/admin/error-logs')->assertStatus(401);
    }

    public function test_lists_unresolved_by_default_with_summary(): void
    {
        $this->actingAsRole('admin');
        $this->seedLog('vk_api', false);
        $this->seedLog('vk_api', false);
        $this->seedLog('job_error', true); // решённая — не должна попасть по умолчанию

        $this->getJson('/api/admin/error-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.total_unresolved', 2)
            ->assertJsonPath('summary.by_type.vk_api', 2);
    }

    public function test_include_resolved_shows_all(): void
    {
        $this->actingAsRole('admin');
        $this->seedLog('vk_api', false);
        $this->seedLog('job_error', true);

        $this->getJson('/api/admin/error-logs?include_resolved=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_filter_by_type_and_q(): void
    {
        $this->actingAsRole('admin');
        $this->seedLog('vk_api', false, 'rate limited');
        $this->seedLog('ml_error', false, 'model timeout');

        $this->getJson('/api/admin/error-logs?type=ml_error')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'ml_error');

        $this->getJson('/api/admin/error-logs?q=rate')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'vk_api');
    }

    public function test_resolve_marks_resolved(): void
    {
        $this->actingAsRole('admin');
        $e = $this->seedLog('job_error', false);

        $this->postJson("/api/admin/error-logs/{$e->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.resolved', true);

        $this->assertTrue(ErrorLog::find($e->id)->resolved);
    }

    public function test_resolve_404_for_missing(): void
    {
        $this->actingAsRole('admin');
        $this->postJson('/api/admin/error-logs/999999/resolve')->assertStatus(404);
    }

    public function test_resolve_all_marks_unresolved_by_filter(): void
    {
        $this->actingAsRole('admin');
        $this->seedLog('general', false);
        $this->seedLog('general', false);
        $this->seedLog('vk_api', false);  // другой тип — не трогаем при type=general
        $this->seedLog('general', true);  // уже решённая

        $this->postJson('/api/admin/error-logs/resolve-all', ['type' => 'general'])
            ->assertOk()
            ->assertJsonPath('data.resolved_count', 2);

        $this->assertSame(0, ErrorLog::where('type', 'general')->where('resolved', false)->count());
        $this->assertSame(1, ErrorLog::where('type', 'vk_api')->where('resolved', false)->count());
    }

    public function test_resolve_all_without_filter_resolves_everything(): void
    {
        $this->actingAsRole('admin');
        $this->seedLog('general', false);
        $this->seedLog('vk_api', false);

        $this->postJson('/api/admin/error-logs/resolve-all', [])
            ->assertOk()
            ->assertJsonPath('data.resolved_count', 2);

        $this->assertSame(0, ErrorLog::where('resolved', false)->count());
    }
}
