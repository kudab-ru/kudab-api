<?php

namespace App\Repositories\Telegram;

use App\Contracts\Telegram\TelegramMessageTemplateRepositoryInterface;
use App\Models\TelegramMessageTemplate;
use Illuminate\Support\Collection;

class TelegramMessageTemplateRepository implements TelegramMessageTemplateRepositoryInterface
{
    public function listActiveByLocale(
        string $locale = 'ru',
    ): Collection {
        return TelegramMessageTemplate::query()
            ->where('locale', $locale)
            ->where('is_active', true)
            // ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function findActiveByCode(
        string $code,
        string $locale = 'ru',
    ): ?TelegramMessageTemplate {
        return TelegramMessageTemplate::query()
            ->where('code', $code)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->first();
    }
}
