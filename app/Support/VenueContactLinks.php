<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Контакты площадки из `venues.source_meta` → массив ссылок для страницы места.
 *
 * ВАЖНО ПРО ДАННЫЕ. На момент написания (2026-08) ни один производитель
 * source_meta ссылок туда не кладёт. Разбор всех писателей в обоих репозиториях
 * (VenueColdResolver, VenueMaterializeFromEvents, VenueBackfillFromCommunities,
 * VenuePromoterFromStructuredMeta, ManualVenueUpsert, AdminVenuesController)
 * даёт только трассировку происхождения: origin, resolved_via, confidence,
 * raw_name, source, source_post_ids, promoted_at, address_enrich, cluster_key,
 * geo_origin, name_source, event_count, event_ids, from_community_id,
 * from_community_name, legal_address, manual_point*. Ни сайта, ни VK, ни
 * телефона среди них нет — поэтому сегодня метод честно возвращает пустой
 * массив на любой реальной площадке.
 *
 * Читатель написан наперёд и зафиксирован как контракт: как только парсер
 * начнёт складывать контакты (задача на его стороне), ссылки появятся на
 * странице без правок API и фронта. Ключи принимаем в нескольких написаниях —
 * дешевле, чем договариваться о единственно верном заранее.
 *
 * Отдаём только то, что похоже на живой контакт: http(s)-ссылку или телефон.
 * Мусорную строку молча пропускаем — на странице места сломанная ссылка хуже
 * её отсутствия.
 */
final class VenueContactLinks
{
    /** @var array<string, list<string>> тип ссылки => принимаемые ключи source_meta */
    private const KEYS = [
        'site' => ['site', 'site_url', 'website', 'url'],
        'vk' => ['vk', 'vk_url'],
        'telegram' => ['telegram', 'tg', 'tg_url'],
        'phone' => ['phone', 'tel'],
    ];

    /** Контейнеры, внутри которых контакты тоже ищем (плоско и во вложенности). */
    private const NESTS = ['contacts', 'links'];

    /**
     * @return list<array{type: string, url: string, label: string}>
     */
    public static function fromSourceMeta(mixed $meta): array
    {
        $flat = self::flatten($meta);
        if ($flat === []) {
            return [];
        }

        $out = [];
        foreach (self::KEYS as $type => $keys) {
            foreach ($keys as $key) {
                $raw = trim((string) ($flat[$key] ?? ''));
                if ($raw === '') {
                    continue;
                }

                $link = $type === 'phone' ? self::phone($raw) : self::web($type, $raw);
                if ($link !== null) {
                    $out[] = $link;

                    break; // один контакт на тип: первое живое написание ключа выигрывает
                }
            }
        }

        return $out;
    }

    /**
     * Плоская карта «ключ → скалярное значение»: сам source_meta плюс один
     * уровень вложенности из contacts/links. Глубже не ходим — гадать о
     * структуре, которой ещё нет, смысла нет.
     *
     * @return array<string, scalar>
     */
    private static function flatten(mixed $meta): array
    {
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        if (! is_array($meta)) {
            return [];
        }

        $flat = [];
        foreach ($meta as $key => $value) {
            if (is_scalar($value)) {
                $flat[(string) $key] = $value;
            }
        }

        foreach (self::NESTS as $nest) {
            $inner = $meta[$nest] ?? null;
            if (! is_array($inner)) {
                continue;
            }
            foreach ($inner as $key => $value) {
                if (is_scalar($value) && ! isset($flat[(string) $key])) {
                    $flat[(string) $key] = $value;
                }
            }
        }

        return $flat;
    }

    /**
     * Подпись сайта — домен без www: «kudab.ru» под названием места читается,
     * голый https://kudab.ru/about?utm=… — нет.
     *
     * @return array{type: string, url: string, label: string}|null
     */
    private static function web(string $type, string $raw): ?array
    {
        if (! preg_match('~^https?://~i', $raw)) {
            return null;
        }

        $host = (string) preg_replace('~^www\.~i', '', (string) (parse_url($raw, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return null;
        }

        $label = match ($type) {
            'vk' => 'ВКонтакте',
            'telegram' => 'Telegram',
            default => $host,
        };

        return ['type' => $type, 'url' => $raw, 'label' => $label];
    }

    /**
     * Телефон отдаём ссылкой tel: (на мобильном это звонок в один тап), а
     * подписью — исходное написание: человеку привычнее «+7 (473) 222-33-44»,
     * чем нормализованная цифробуква.
     *
     * @return array{type: string, url: string, label: string}|null
     */
    private static function phone(string $raw): ?array
    {
        $digits = (string) preg_replace('~\D+~', '', $raw);
        // 10 цифр — местный номер без кода страны, 11 — с ним; короче не бывает
        if (mb_strlen($digits) < 10 || mb_strlen($digits) > 15) {
            return null;
        }

        return [
            'type' => 'phone',
            'url' => 'tel:'.(str_starts_with($raw, '+') ? '+' : '').$digits,
            'label' => $raw,
        ];
    }
}
