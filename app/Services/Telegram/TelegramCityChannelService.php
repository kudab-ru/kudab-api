<?php

namespace App\Services\Telegram;

use App\Models\City;
use App\Models\TelegramCityChannel;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TelegramCityChannelService
{
    public function resolve(?string $requestedSlug): array
    {
        $requestedSlug = $this->normalizeSlug($requestedSlug);

        $requestedCity = null;
        if ($requestedSlug) {
            $requestedCity = City::query()
                ->where('slug', $requestedSlug)
                ->first();
        }

        $channel = null;

        if ($requestedCity) {
            $channel = TelegramCityChannel::query()
                ->with('city')
                ->active()
                ->where('city_id', $requestedCity->id)
                ->first();
        }

        $isFallback = false;

        if (! $channel) {
            $channel = TelegramCityChannel::query()
                ->with('city')
                ->active()
                ->default()
                ->first();

            $isFallback = true;
        }

        if (! $channel) {
            throw new ModelNotFoundException('Default telegram city channel not configured.');
        }

        $resolvedCity = $channel->city;

        return [
            'city' => $requestedSlug,
            'resolved_city' => $resolvedCity?->slug,
            'telegram_url' => $channel->telegram_url,
            'telegram_username' => $channel->telegram_username,
            'is_fallback' => $isFallback,
        ];
    }

    private function normalizeSlug(?string $slug): ?string
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }

        return mb_strtolower($slug);
    }
}
