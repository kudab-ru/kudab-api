<?php

namespace App\Contracts\Telegram;

use App\Models\TelegramMessageTemplate;
use Illuminate\Support\Collection;

interface TelegramMessageTemplateRepositoryInterface
{
    public function listActiveByLocale(
        string $locale = 'ru',
    ): Collection;

    public function findActiveByCode(
        string $code,
        string $locale = 'ru',
    ): ?TelegramMessageTemplate;
}
