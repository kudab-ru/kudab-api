<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Подпись соцсети для строки-атрибуции: слаг из `social_networks` → название,
 * которое можно показать человеку.
 *
 * Имена в самой таблице писались для админки и на странице места читаются
 * плохо: «VK» вместо «ВКонтакте», «Афиша/Сайт» вместо «Сайт», «Qtickets
 * Афиша» — с лишним словом. Поэтому подпись держим здесь, а `name` из базы
 * оставляем запасным вариантом для сетей, которых на момент написания ещё нет.
 *
 * Тон нейтральный: это подпись ссылки, а не знак доверия. Слов «официальный»
 * и «проверено» тут не будет — мы этого про сообщество не знаем
 * (см. .claude/guidelines/source-attribution-research.md в kudab-frontend).
 */
final class SocialNetworkLabel
{
    /** @var array<string, string> слаг social_networks => подпись */
    private const LABELS = [
        'vk' => 'ВКонтакте',
        'telegram' => 'Telegram',
        'site' => 'Сайт',
        'yandex_afisha' => 'Яндекс.Афиша',
        'qtickets' => 'Qtickets',
    ];

    public static function for(?string $slug, ?string $fallbackName = null): string
    {
        $key = mb_strtolower(trim((string) $slug), 'UTF-8');

        return self::LABELS[$key]
            ?? (trim((string) $fallbackName) !== '' ? trim((string) $fallbackName) : $key);
    }
}
