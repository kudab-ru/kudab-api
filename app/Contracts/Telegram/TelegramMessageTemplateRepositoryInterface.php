<?php

namespace App\Contracts\Telegram;

use App\Models\TelegramMessageTemplate;
use Illuminate\Support\Collection;

interface TelegramMessageTemplateRepositoryInterface
{
    /**
     * Список активных шаблонов для бота по локали.
     *
     * @param  string  $locale  Локаль (по умолчанию ru)
     */
    public function listActiveByLocale(
        string $locale = 'ru',
    ): Collection;

    /**
     * Найти активный шаблон по коду и локали.
     */
    public function findActiveByCode(
        string $code,
        string $locale = 'ru',
    ): ?TelegramMessageTemplate;
}
