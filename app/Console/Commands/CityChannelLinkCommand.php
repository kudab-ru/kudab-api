<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\TelegramCityChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CityChannelLinkCommand extends Command
{
    protected $signature = 'city:channel-link
        {city : City slug or id}
        {--url= : Public telegram URL, example: https://t.me/kudab_vrn}
        {--username= : Telegram username, with or without @}
        {--default : Mark as default fallback channel}
        {--off : Disable existing link for city}
        {--dry-run : Do not write changes}';

    protected $description = 'Привязать публичный Telegram-канал к городу (с fallback-каналом по умолчанию)';

    public function handle(): int
    {
        $arg = trim((string) $this->argument('city'));
        $url = $this->normalizeUrl($this->option('url'));
        $username = $this->normalizeUsername($this->option('username'));
        $isDefault = (bool) $this->option('default');
        $off = (bool) $this->option('off');
        $dry = (bool) $this->option('dry-run');

        $city = City::query()
            ->when(
                ctype_digit($arg),
                fn ($q) => $q->where('id', (int) $arg),
                fn ($q) => $q->where('slug', $arg)
            )
            ->first();

        if (! $city) {
            $this->error("Город не найден: {$arg}");
            return self::FAILURE;
        }

        $existing = TelegramCityChannel::query()
            ->where('city_id', $city->id)
            ->first();

        if ($off) {
            if (! $existing) {
                $this->warn("У города {$city->name} ({$city->slug}) привязки нет — выключать нечего.");
                return self::SUCCESS;
            }

            $this->info(
                "City {$city->name} ({$city->slug}, id={$city->id}): disable telegram channel"
                . ($dry ? ' [dry-run]' : '')
            );

            if ($dry) {
                return self::SUCCESS;
            }

            $existing->is_active = false;
            $existing->is_default = false;
            $existing->save();

            $this->info('Привязка выключена.');
            return self::SUCCESS;
        }

        if (! $url) {
            $this->error('Нужно передать --url=https://t.me/...');
            return self::FAILURE;
        }

        if (! $username) {
            $username = $this->usernameFromUrl($url);
        }

        $this->line("City: {$city->name} ({$city->slug}, id={$city->id})");
        $this->line("URL: {$url}");
        $this->line("Username: " . ($username ?: '-'));
        $this->line("Default: " . ($isDefault ? 'yes' : 'no'));
        $this->line("Mode: " . ($existing ? 'update' : 'create') . ($dry ? ' [dry-run]' : ''));

        if ($dry) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($city, $url, $username, $isDefault) {
            if ($isDefault) {
                TelegramCityChannel::query()
                    ->where('is_default', true)
                    ->update([
                        'is_default' => false,
                        'updated_at' => now(),
                    ]);
            }

            $channel = TelegramCityChannel::query()->firstOrNew([
                'city_id' => $city->id,
            ]);

            $channel->telegram_url = $url;
            $channel->telegram_username = $username;
            $channel->is_active = true;
            $channel->is_default = $isDefault;
            $channel->save();
        });

        $saved = TelegramCityChannel::query()
            ->where('city_id', $city->id)
            ->first();

        $this->info(
            "Сохранено: city={$city->slug}, url={$saved?->telegram_url}, username=" . ($saved?->telegram_username ?: '-')
            . ', active=' . ((int) (bool) $saved?->is_active)
            . ', default=' . ((int) (bool) $saved?->is_default)
        );

        return self::SUCCESS;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return null;
        }

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'https://' . ltrim($url, '/');
        }

        return $url;
    }

    private function normalizeUsername(mixed $value): ?string
    {
        $username = trim((string) ($value ?? ''));
        if ($username === '') {
            return null;
        }

        $username = ltrim($username, '@');

        return $username !== '' ? mb_strtolower($username) : null;
    }

    private function usernameFromUrl(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }

        // t.me/foo, telegram.me/foo, без глубоких путей
        $first = explode('/', $path)[0] ?? '';
        $first = trim($first);

        return $first !== '' ? mb_strtolower($first) : null;
    }
}
