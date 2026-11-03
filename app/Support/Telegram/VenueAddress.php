<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/** Адрес площадки для текста в Telegram: портрет площадки в канале и напоминание «Пойду» в боте. */
final class VenueAddress
{
    /** Короткий адрес: улица и дом; иначе адрес без индекса, области и города, обрезанный. */
    public static function short(object $venue): string
    {
        $street = trim((string) ($venue->street ?? ''));
        $house = trim((string) ($venue->house ?? ''));
        if ($street !== '') {
            return $house !== '' ? $street.', '.$house : $street;
        }
        $addr = trim((string) ($venue->address ?? ''));
        if ($addr === '') {
            return '';
        }
        $addr = (string) preg_replace('/^\s*\d{5,6}\s*,\s*/u', '', $addr);          // индекс
        $addr = (string) preg_replace('/^[^,]*\bобл[^,]*,\s*/ui', '', $addr);        // область
        $addr = (string) preg_replace('/^\s*г\.?\s+[^,]+,\s*/ui', '', $addr);        // город
        $addr = trim($addr, ' ,');

        return mb_strlen($addr) > 60 ? mb_substr($addr, 0, 57).'…' : $addr;
    }

    /** Площадка на Яндекс Картах: точка по координатам, без них — поиск по адресу. */
    public static function mapsUrl(object $venue): string
    {
        if ($venue->latitude !== null && $venue->longitude !== null) {
            $ll = ((string) $venue->longitude).','.((string) $venue->latitude);

            return 'https://yandex.ru/maps/?ll='.$ll.'&z=17&pt='.$ll;
        }
        $q = trim((string) ($venue->address ?: $venue->name));

        return 'https://yandex.ru/maps/?text='.rawurlencode($q);
    }

    /** Маршрут до площадки от того места, где человек сейчас; без координат — сама площадка на карте. */
    public static function routeUrl(object $venue): string
    {
        if ($venue->latitude === null || $venue->longitude === null) {
            return self::mapsUrl($venue);
        }

        return 'https://yandex.ru/maps/?rtext=~'.$venue->latitude.','.$venue->longitude.'&rtt=auto';
    }
}
