<?php

namespace App\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface SocialMediaServiceInterface
{
    /**
     * Получить последние посты из группы VK (универсальный массив).
     *
     * @param  int|string  $externalCommunityId  Внешний ID сообщества (из community_social_links)
     * @param  int  $limit  Сколько постов запрашивать (до 100)
     * @param  int  $offset  Смещение для пэйджинга
     * @param  CarbonInterface|null  $since  Только посты после этой даты (по времени публикации)
     * @param  string|null  $sinceId  Только посты после этого VK post_id (опционально)
     * @param  array  $options  Любые дополнительные параметры (future-proof)
     * @return Collection<array> Массивы-объекты постов (НЕ Eloquent)
     */
    public function getLatestPosts(
        int|string $externalCommunityId,
        int $limit = 10,
        int $offset = 0,
        ?\Carbon\CarbonInterface $since = null,
        ?string $sinceId = null,
        array $options = []
    ): Collection;

    /**
     * Получить посты VK за указанный период (универсальные массивы).
     *
     * @return Collection<array>
     */
    public function getPostsByDate(
        int|string $externalCommunityId,
        \Carbon\CarbonInterface $startDate,
        ?\Carbon\CarbonInterface $endDate = null,
        array $options = []
    ): Collection;

    /**
     * Получить подробную информацию о сообществе VK (универсальный массив).
     */
    public function getCommunityInfo(int|string $externalCommunityId): array;

    /**
     * Краткое нормализованное описание сообщества из профиля источника.
     * Длина может быть ограничена реализацией (например, до ~1200 символов).
     */
    public function getCommunityDescription(int|string $externalCommunityId): ?string;

    /**
     * Город сообщества из настроек источника (если указан).
     * Возвращает нормализованное название города или null.
     */
    public function getCommunityCity(int|string $externalCommunityId): ?string;

    /**
     * Сгенерировать ссылку на пост/ивент VK.
     */
    public function generateEventLink(int|string $sourceId, int|string|null $externalCommunityId = null): string;

    /**
     * Сгенерировать ссылку на сообщество VK.
     */
    public function generateCommunityLink(int|string $externalCommunityId): string;

    /**
     * Получить информацию о текущем состоянии лимитов VK API (заглушка, реализовать позже).
     */
    public function getRateLimitState(): array;
}
