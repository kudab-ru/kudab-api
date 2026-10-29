<?php

namespace Tests\Feature\Api;

use App\Models\TelegramUser;
use App\Models\User;
use App\Notifications\KulisyVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KulisyAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $botToken = 'test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['kulisy.enabled' => true, 'kulisy.site_url' => 'http://localhost:3000/kulisy', 'services.bot.shared_token' => $this->botToken]);
        Role::findOrCreate('organizer', 'web');
    }

    private function organizer(array $attrs = []): User
    {
        $user = User::factory()->create($attrs + ['email' => 'litera.vrn@mail.ru']);
        $user->assignRole('organizer');

        return $user;
    }

    private function bot(string $code, string $answer = 'yes', int $telegramId = 777): array
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->botToken)
            ->postJson('/api/bot/kulisy/confirm', ['code' => $code, 'answer' => $answer, 'telegram_id' => $telegramId, 'first_name' => 'Анна'])
            ->assertOk()->json('data');
    }

    public function test_switched_off_kulisy_answers_not_found(): void
    {
        config(['kulisy.enabled' => false]);

        $this->postJson('/api/kulisy/auth/login', ['email' => 'a@b.ru', 'password' => 'x'])->assertNotFound();
    }

    public function test_registration_waits_for_the_letter_and_the_link_confirms_email(): void
    {
        Notification::fake();

        $token = $this->postJson('/api/kulisy/auth/register', ['email' => 'Litera.Vrn@mail.ru', 'password' => 'litera-2026'])
            ->assertOk()->assertJsonPath('data.user.email_verified', false)->json('data.token');

        $user = User::where('email', 'litera.vrn@mail.ru')->firstOrFail();
        $this->assertTrue($user->hasRole('organizer'));

        $url = null;
        Notification::assertSentTo($user, KulisyVerifyEmail::class, function ($n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertRedirect('http://localhost:3000/kulisy?pochta=ok');

        $this->withToken($token)->getJson('/api/kulisy/auth/me')->assertJsonPath('data.email_verified', true);
        $this->postJson('/api/kulisy/auth/register', ['email' => 'litera.vrn@mail.ru', 'password' => 'другой-пароль'])
            ->assertStatus(422)->assertJsonPath('code', 'email_taken');
    }

    #[DataProvider('loginFailures')]
    public function test_login_explains_why_it_failed(array $attrs, string $password, string $code): void
    {
        Notification::fake();
        if ($attrs !== []) {
            $this->organizer($attrs);
        }

        $this->postJson('/api/kulisy/auth/login', ['email' => 'litera.vrn@mail.ru', 'password' => $password])
            ->assertStatus(422)->assertJsonPath('code', $code);
    }

    public static function loginFailures(): array
    {
        return [
            'почты нет' => [[], 'litera-2026', 'no_account'],
            'пароль не тот' => [['password' => 'litera-2026'], 'litera2025', 'wrong_password'],
            'вошёл через Telegram' => [['password' => null], 'litera-2026', 'no_password'],
            'почта не подтверждена' => [['password' => 'litera-2026', 'email_verified_at' => null], 'litera-2026', 'not_verified'],
        ];
    }

    public function test_login_with_password_gives_a_token(): void
    {
        $this->organizer(['password' => 'litera-2026']);

        $token = $this->postJson('/api/kulisy/auth/login', ['email' => 'litera.vrn@mail.ru', 'password' => 'litera-2026'])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/kulisy/auth/me')->assertOk()->assertJsonPath('data.email', 'litera.vrn@mail.ru');
    }

    public function test_registration_through_the_bot_creates_an_organizer_and_hands_the_token_once(): void
    {
        $code = $this->postJson('/api/kulisy/auth/telegram', ['intent' => 'register'])->assertOk()->json('data.code');
        $this->getJson("/api/kulisy/auth/telegram/{$code}")->assertJsonPath('data.status', 'pending');

        $this->assertSame('confirmed', $this->bot($code)['status']);

        $this->getJson("/api/kulisy/auth/telegram/{$code}")->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.user.telegram', true);
        $this->getJson("/api/kulisy/auth/telegram/{$code}")->assertJsonPath('data.status', 'expired');

        $user = User::findOrFail(TelegramUser::where('telegram_id', 777)->value('user_id'));
        $this->assertTrue($user->hasRole('organizer'));
        $this->assertNull($user->email);
    }

    public function test_bot_login_without_account_or_with_no_answer_does_not_sign_in(): void
    {
        $code = $this->postJson('/api/kulisy/auth/telegram', ['intent' => 'login'])->json('data.code');
        $this->assertSame('no_account', $this->bot($code)['status']);
        $this->getJson("/api/kulisy/auth/telegram/{$code}")->assertJsonPath('data.status', 'no_account');

        $code = $this->postJson('/api/kulisy/auth/telegram', ['intent' => 'register'])->json('data.code');
        $this->assertSame('declined', $this->bot($code, 'no')['status']);
        $this->getJson("/api/kulisy/auth/telegram/{$code}")->assertJsonPath('data.status', 'declined');
        $this->assertSame(0, User::count());
    }
}
