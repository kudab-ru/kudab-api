<?php

namespace Tests\Feature\Api;

use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\EventCaptionBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\TelegramMessageTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminBroadcastPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TelegramMessageTemplatesSeeder::class);

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    public function test_feed_preview_uses_the_form_of_the_publish_day(): void
    {
        $broadcast = $this->makeChannel();
        $event = $this->makeEvent();

        $publishAt = null;
        foreach (range(1, 3) as $days) {
            $candidate = CarbonImmutable::now('Europe/Moscow')->addDays($days)->setTime(10, 0);
            if ($broadcast->templateCodeForDate($candidate) !== 'basic') {
                $publishAt = $candidate;
                break;
            }
        }
        $this->assertNotNull($publishAt);

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $item->event_id = $event->id;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
        $item->caption = 'старая подпись';
        $item->publish_at = $publishAt;
        $item->save();

        $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/feed")->assertOk();

        $expected = app(EventCaptionBuilder::class)->build(
            $event->fresh(),
            $broadcast->templateCodeForDate($publishAt),
            $publishAt,
            (int) $item->id,
        );
        $this->assertSame($expected, (string) $item->fresh()->caption);
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201799]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999045;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $this->cityId();
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => [
                'period' => 'daily_10',
                'template_code' => 'basic',
                'template_rotation' => ['basic', 'lead-below', 'quote'],
            ],
        ]);
    }

    private function makeEvent(): Event
    {
        $community = Community::create(['name' => 'Организатор', 'city_id' => $this->cityId()]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->title = 'Спектакль «Сказочник»';
        $event->status = 'active';
        $event->city_id = $this->cityId();
        $event->city = 'Воронеж';
        $event->address = 'г Воронеж, ул Мира, д 1';
        $event->start_time = now()->addDays(5)->setTime(16, 0);
        $event->start_date = now()->addDays(5)->toDateString();
        $event->tg_description = 'Старый сказочник собирает героев своих книг в одной комнате.';
        $event->save();

        return $event;
    }

    private function cityId(): int
    {
        $id = DB::table('cities')->where('slug', 'voronezh')->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh', now(), now()],
        );

        return (int) DB::table('cities')->where('slug', 'voronezh')->value('id');
    }
}
