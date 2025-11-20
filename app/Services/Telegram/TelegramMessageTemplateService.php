<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramMessageTemplateRepositoryInterface;
use App\Models\TelegramMessageTemplate;
use Illuminate\Support\Collection;

class TelegramMessageTemplateService
{
    public function __construct(
        private readonly TelegramMessageTemplateRepositoryInterface $templateRepository,
    ) {}

    /**
     * Активные шаблоны для рассылки одного события.
     * Пока других типов нет, отдаём все активные для локали.
     */
    public function listSingleTemplates(string $locale = 'ru'): Collection
    {
        return $this->templateRepository->listActiveByLocale($locale);
    }

    /**
     * Найти конкретный активный шаблон по коду.
     */
    public function findActiveSingleByCode(string $code, string $locale = 'ru'): ?TelegramMessageTemplate
    {
        return $this->templateRepository->findActiveByCode($code, $locale);
    }
}
