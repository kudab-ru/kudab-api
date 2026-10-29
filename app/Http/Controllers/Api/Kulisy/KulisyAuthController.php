<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Kulisy;

use App\Http\Requests\Kulisy\LoginRequest;
use App\Http\Requests\Kulisy\RegisterRequest;
use App\Models\User;
use App\Notifications\KulisyVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * Вход и регистрация организаторов. Ответ на ошибку несёт `code`: по нему страница
 * выбирает подсказку из схемы входа (design/kulisy-shema-vhoda.html).
 */
class KulisyAuthController extends Controller
{
    public const ROLES = ['organizer', 'admin', 'superadmin'];

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = mb_strtolower(trim($data['email']));

        if (User::where('email', $email)->exists()) {
            return $this->fail('email_taken', 'эта почта уже зарегистрирована');
        }

        $user = User::create([
            'name' => explode('@', $email)[0],
            'email' => $email,
            'password' => $data['password'],
        ]);
        $user->assignRole('organizer');
        $this->sendVerification($user);

        return $this->signedIn($user, $request);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = mb_strtolower(trim($data['email']));

        $key = 'kulisy-login:'.$email.'|'.($request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->fail('too_many', 'слишком много попыток, попробуйте через '.RateLimiter::availableIn($key).' с', 429);
        }

        $user = User::where('email', $email)->first();
        if (! $user || ! $user->hasAnyRole(self::ROLES)) {
            return $this->fail('no_account', 'такой почты ещё нет');
        }
        if ($user->password === null) {
            return $this->fail('no_password', 'пароля ещё нет: вы входили через Telegram');
        }
        if (! Hash::check((string) ($data['password'] ?? ''), $user->password)) {
            RateLimiter::hit($key, 60);

            return $this->fail('wrong_password', 'пароль не подошёл');
        }
        if ($user->email_verified_at === null) {
            $this->sendVerification($user);

            return $this->fail('not_verified', 'почта ещё не подтверждена, письмо отправили ещё раз');
        }

        RateLimiter::clear($key);

        return $this->signedIn($user, $request);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => self::serialize($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->email !== null && $user->email_verified_at === null) {
            $this->sendVerification($user);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::find($id);
        $ok = $user && $user->email !== null && hash_equals(sha1($user->email), $hash);
        if ($ok && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect()->away(rtrim((string) config('kulisy.site_url'), '/').($ok ? '?pochta=ok' : '?pochta=bad'));
    }

    public static function serialize(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'email' => $user->email,
            'name' => (string) ($user->name ?? ''),
            'email_verified' => $user->email_verified_at !== null,
            'has_password' => $user->password !== null,
            'telegram' => $user->telegramUsers()->exists(),
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }

    public static function issueToken(User $user, Request $request): string
    {
        $name = sprintf('kulisy:%s:%s', mb_substr((string) $request->userAgent(), 0, 120), $request->ip() ?? 'unknown');

        return $user->createToken($name, ['kulisy'], now()->addDays((int) config('kulisy.token_days')))->plainTextToken;
    }

    private function signedIn(User $user, Request $request): JsonResponse
    {
        return response()->json(['data' => ['token' => self::issueToken($user, $request), 'user' => self::serialize($user)]]);
    }

    // Ссылка подписана без адреса сайта: на стенде и на проде API открывается с разных адресов.
    private function sendVerification(User $user): void
    {
        $path = URL::temporarySignedRoute('kulisy.verify', now()->addDay(), ['id' => $user->id, 'hash' => sha1((string) $user->email)], absolute: false);
        $site = parse_url((string) config('kulisy.site_url'));
        $origin = ($site['scheme'] ?? 'https').'://'.($site['host'] ?? 'kulisy.kudab.ru').(isset($site['port']) ? ':'.$site['port'] : '');
        $user->notify(new KulisyVerifyEmail($origin.$path));
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
