<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Rink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminRinksTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_rink_comes_hidden_with_its_own_address_on_the_site(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
        // у города обязательна точка на карте, через `City::create` её не задать
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, now(), now())',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh'],
        );

        $body = ['name' => 'Каток в Центральном парке', 'indoor' => false, 'lat' => 51.70, 'lon' => 39.19];
        $this->postJson('/api/admin/rinks', $body)->assertCreated();
        $this->postJson('/api/admin/rinks', $body)->assertCreated();

        $this->assertSame(
            [['katok-v-centralnom-parke', true], ['katok-v-centralnom-parke-2', true]],
            Rink::query()->orderBy('id')->get()->map(fn (Rink $r) => [$r->slug, $r->hidden])->all(),
        );
    }
}
