<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Падежи города для текстов, которые собирает api.
 *
 * ВТОРОЙ ЭКЗЕМПЛЯР КАРТЫ, и это осознанно. Первый живёт во фронте
 * (`kudab-frontend/app/entities/city/declension.ts`) и обслуживает заголовки
 * и SEO; общего источника у PHP и TypeScript нет, а тащить ради четырнадцати
 * строк таблицу в базу дороже, чем держать их рядом. Правите одну — правьте
 * вторую: формы те же.
 *
 * Неизвестный город возвращает null, а НЕ именительный падеж. «На выходных в
 * Воронеж 136 событий» хуже, чем «На выходных 136 событий»: первое читается
 * как ошибка, второе — как решение.
 */
final class CityCase
{
    /** Предложный падеж: «в <Loc>». Ключ — slug города, как во фронте. */
    private const PREPOSITIONAL = [
        'voronezh' => 'Воронеже',
        'moskva' => 'Москве',
        'sankt-peterburg' => 'Санкт-Петербурге',
        'kazan' => 'Казани',
        'ekaterinburg' => 'Екатеринбурге',
        'krasnoiarsk' => 'Красноярске',
        'niznii-novgorod' => 'Нижнем Новгороде',
        'novosibirsk' => 'Новосибирске',
        'omsk' => 'Омске',
        'perm' => 'Перми',
        'rostov-na-donu' => 'Ростове-на-Дону',
        'samara' => 'Самаре',
        'ufa' => 'Уфе',
        'celiabinsk' => 'Челябинске',
    ];

    public static function prepositional(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return self::PREPOSITIONAL[$slug] ?? null;
    }
}
