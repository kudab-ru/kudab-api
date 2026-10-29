<?php

declare(strict_types=1);

namespace App\Services\Kulisy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Одноразовые коды входа через бота: сайт заводит код, бот отмечает, кто нажал «Да»,
 * сайт забирает результат. Код живёт в кеше kulisy.telegram_code_ttl секунд.
 */
final class TelegramLoginCodes
{
    public const INTENTS = ['login', 'register'];

    public function create(string $intent): string
    {
        $code = Str::lower(Str::random(16));
        $this->put($code, ['intent' => $intent, 'status' => 'pending', 'user_id' => null]);

        return $code;
    }

    public function get(string $code): ?array
    {
        return Cache::get($this->key($code));
    }

    public function put(string $code, array $value): void
    {
        Cache::put($this->key($code), $value, (int) config('kulisy.telegram_code_ttl'));
    }

    public function forget(string $code): void
    {
        Cache::forget($this->key($code));
    }

    private function key(string $code): string
    {
        return 'kulisy:tg:'.$code;
    }
}
