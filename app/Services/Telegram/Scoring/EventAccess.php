<?php

declare(strict_types=1);

namespace App\Services\Telegram\Scoring;

/**
 * Как попасть на событие: что человеку сделать и когда решать. Считается из темы, вида площадки,
 * цены и названия; признаки — EventInterestSignals. Порядок проверок в of() значим.
 */
final class EventAccess
{
    /** Зайти без билета: выставка, музей, кино, ярмарка, бесплатное без записи. */
    public const WALK = 'walk';

    /** Записаться заранее: квиз, мастер-класс, экскурсия, лекция по регистрации. */
    public const SIGN = 'sign';

    /** Билет в бар или клуб: недорого, решают в день или накануне. */
    public const BAR = 'bar';

    /** Билет на большую сцену: театр, опера, филармония, цирк, концерт в зале. Берут заранее. */
    public const STAGE = 'stage';

    /** На улице. Пока узнаётся только по виду площадки «Парки». */
    public const OUT = 'out';

    /** В канал не годится: квест-комнаты с сеансом каждый день, трансляции матчей в барах. */
    public const SKIP = 'skip';

    public const LABELS = [
        self::WALK => 'зайти без билета',
        self::SIGN => 'записаться',
        self::BAR => 'бар или клуб',
        self::STAGE => 'большая сцена',
        self::OUT => 'на улице',
        self::SKIP => 'не для канала',
    ];

    /** Подпись на сайте: можно просто прийти, билет на месте, нужно записаться, лучше брать билеты заранее. */
    public const ENTRIES = ['come', 'door', 'signup', 'ahead'];

    /** Спектакль с чужой темой узнаётся по названию: «Спектакль „Имя“» размечен вечеринкой. */
    private const STAGE_TITLE = '/^(спектакль|моноспектакль|мюзикл|балет|опера|оперетта)(?!\p{L})/iu';

    /** Запись на занятие, а не «зайти». В музее сюда же лекция: туда берут билет на час. */
    private const CLASS_TITLE = '/мастер-класс|экскурси|квест|занятие/iu';

    private const MUSEUM_CLASS_TITLE = '/мастер-класс|экскурси|квест|занятие|лекци/iu';

    private const BROADCAST_TITLE = '/трансляци|лига чемпионов/iu';

    private const SIGNUP_WORDS = '/регистрац|запис/iu';

    /**
     * @param  array{slug?: string|null, parent?: string|null, venue_kind?: string|null}  $signal
     * @param  object  $e  событие или строка выборки: title, price_status, price_text
     */
    public static function of(array $signal, object $e): string
    {
        $slug = $signal['slug'] ?? null;
        $kind = $signal['parent'] ?? $slug;
        $venue = $signal['venue_kind'] ?? null;
        $title = trim((string) ($e->title ?? ''));
        $free = in_array($e->price_status ?? null, ['free', 'donation'], true);
        $signup = preg_match(self::SIGNUP_WORDS, (string) ($e->price_text ?? '')) === 1;

        if ($venue === 'Квесты' || preg_match(self::BROADCAST_TITLE, $title) === 1) {
            return self::SKIP;
        }
        if (in_array($kind, ['theatre', 'circus-show'], true)
            || $slug === 'classical'
            || (in_array($kind, ['music', 'standup'], true) && $venue === 'Сцены')
            || preg_match(self::STAGE_TITLE, $title) === 1) {
            return self::STAGE;
        }
        if ($venue === 'Парки') {
            return self::OUT;
        }
        if (in_array($kind, ['exhibitions', 'cinema', 'markets'], true) || in_array($venue, ['Музеи', 'Кинотеатры'], true)) {
            return preg_match(self::MUSEUM_CLASS_TITLE, $title) === 1 || $signup ? self::SIGN : self::WALK;
        }
        if (in_array($kind, ['quiz-games', 'excursions'], true) || $slug === 'workshops' || preg_match(self::CLASS_TITLE, $title) === 1) {
            return self::SIGN;
        }
        if (in_array($kind, ['music', 'standup', 'parties', 'festival'], true)) {
            return $free && $kind !== 'parties' && $venue !== 'Клубы и бары' ? self::WALK : self::BAR;
        }

        return $free && ! $signup ? self::WALK : self::SIGN;
    }

    /** Подпись для сайта по категории и цене; null — подписывать нечем. Пара к applyEntry, правятся вместе. */
    public static function entry(?string $access, ?string $priceStatus): ?string
    {
        $free = in_array($priceStatus, ['free', 'donation'], true);

        return match ($access) {
            self::WALK, self::OUT, self::BAR => $free ? 'come' : 'door',
            self::SIGN => 'signup',
            self::STAGE => 'ahead',
            default => null,
        };
    }

    /**
     * Фильтр афиши по подписи, то же правило, что entry().
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public static function applyEntry($query, string $entry, string $table = 'events'): void
    {
        $free = fn ($q) => $q->whereIn($table.'.price_status', ['free', 'donation']);

        match ($entry) {
            'come' => $query->whereIn($table.'.access', [self::WALK, self::OUT, self::BAR])->where($free),
            'door' => $query->whereIn($table.'.access', [self::WALK, self::OUT, self::BAR])
                ->whereRaw("coalesce({$table}.price_status, '') not in ('free', 'donation')"),
            'signup' => $query->where($table.'.access', self::SIGN),
            'ahead' => $query->where($table.'.access', self::STAGE),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
