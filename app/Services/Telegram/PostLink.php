<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Метка на ссылке поста — одна на все виды постов.
 *
 * ЗАЧЕМ. Переходы — единственный прибор отклика, который у канала возможен:
 * просмотры Bot API не отдаёт вовсе. Прибор работает ровно настолько, насколько
 * размечены ссылки, а размечена была одна из шести: у поста события — «Подробнее
 * на kudab.ru», а три ссылки подборки на карточки событий и три ссылки портрета
 * площадки уходили голыми. То есть треть кликов канала не считалась никогда.
 *
 * ЧТО В МЕТКЕ. `utm_content = i<номер записи>` — номер ПОСТА, а не события:
 * мерим, сработал ли пост, и по какой из его ссылок человек нажал, нам не важно.
 * Поэтому все ссылки одного поста несут одну метку, и счётчик складывает их в
 * одно число.
 *
 * `utm_medium` различает виды: `post` (событие), `digest` (подборка), `venue`
 * (портрет площадки). По нему в Метрике видно, какой формат вообще работает.
 */
final class PostLink
{
    public const MEDIUM_EVENT = 'post';

    public const MEDIUM_DIGEST = 'digest';

    public const MEDIUM_VENUE = 'venue';

    /**
     * @param  int|null  $itemId  запись очереди; null — пост собирают не для
     *                            канала (превью шаблона), и мерить там нечего
     */
    public static function utm(string $url, string $medium, ?int $itemId): string
    {
        if ($itemId === null || $url === '') {
            return $url;
        }

        $source = (string) (config('broadcast_digest.utm.source') ?: 'tg');
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'utm_source='.$source.'&utm_medium='.$medium.'&utm_content=i'.$itemId;
    }
}
