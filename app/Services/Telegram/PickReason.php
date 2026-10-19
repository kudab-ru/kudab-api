<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Telegram\Scoring\EventAccess;
use App\Services\Telegram\Scoring\EventBroadcastScorer;
use Carbon\Carbon;

/**
 * Почему пост стоит в ленте, одной строкой. Строку собирает только этот класс из `pick_meta`
 * записи; баллов и числа кандидатов в ней нет. Пишут `pick_meta` все пути выбора через meta().
 */
final class PickReason
{
    public const BY_AUTO = 'auto';

    public const BY_WAITING = 'waiting';

    public const BY_HAND = 'hand';

    public const BY_PORTRAIT = 'portrait';

    public const BY_DIGEST = 'digest';

    /** Правило автовыбора в слот «по возможности»; число событий дня — в `pick_meta.supply`. */
    public const RULE_OPTIONAL = 'optional';

    /** Мягкие слои подбора, ключи `pick_meta.dropped`: слой, без которого кандидата не нашлось. */
    public const LAYER_THEME = 'theme';

    public const LAYER_TITLE = 'title';

    public const LAYER_VENUE = 'venue';

    /** Части карточки из разбора балла в порядке текста. Билеты не часть карточки. */
    private const CARD_PARTS = [
        'photo' => 'фото',
        'venue' => 'площадки',
        'address' => 'адреса',
        'description' => 'описания',
        'price' => 'цены',
        'time' => 'точного времени',
        'theme' => 'темы',
    ];

    /** Вид события в объяснении выбора; ключ — первичная тема или её родитель. */
    private const KIND_WORDS = [
        'music' => 'концерт',
        'theatre' => 'спектакль',
        'standup' => 'стендап',
        'festival' => 'фестиваль',
        'circus-show' => 'шоу',
        'parties' => 'вечеринка',
        'exhibitions' => 'выставка',
        'cinema' => 'кино',
        'education' => 'лекция или мастер-класс',
        'excursions' => 'экскурсия',
        'quiz-games' => 'игра',
        'kids' => 'детское',
        'city' => 'городское событие',
        'sport' => 'спорт',
        'literature' => 'литература',
    ];

    /** Чего не хватает посту — по штрафам балла. */
    private const WEAK_PARTS = [
        'photo' => 'без афиши',
        'text' => 'короткое описание',
        'time' => 'без точного времени',
        'venue' => 'без площадки',
    ];

    /** Что нарушено и как в нужном роде сказать «другой не нашлось». */
    private const LAYERS = [
        self::LAYER_THEME => ['тема совпала с соседним постом', 'другой'],
        self::LAYER_TITLE => ['такое название уже было за две недели', 'другого'],
        self::LAYER_VENUE => ['площадка уже есть в ленте', 'другой'],
    ];

    private const TZ = 'Europe/Moscow';

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function meta(string $by, string $rule, array $extra = []): array
    {
        return ['by' => $by, 'rule' => $rule, 'at' => Carbon::now()->setTimezone(self::TZ)->toIso8601String()] + $extra;
    }

    /** @param  array<string, mixed>|null  $meta  pick_meta записи */
    public static function forItem(?array $meta): string
    {
        $meta ??= [];

        $movedAt = self::date($meta['moved']['at'] ?? null);
        if ($movedAt !== null) {
            return 'Перевешено на двойник: карточку слили '.$movedAt->format('d.m');
        }

        return match ($meta['by'] ?? null) {
            self::BY_AUTO => self::auto($meta),
            self::BY_WAITING => self::waiting($meta),
            self::BY_HAND => self::hand($meta),
            self::BY_PORTRAIT => 'Подошёл срок портрета площадки',
            self::BY_DIGEST => 'Подборка по расписанию канала',
            default => 'Выбрано до перехода',
        };
    }

    /** @param  array<string, mixed>  $meta  разбор кандидата в той же форме, что у автовыбора */
    public static function forCandidate(array $meta): string
    {
        $parts = [isset($meta['closes_in']) ? self::run((int) $meta['closes_in']) : self::card($meta, comparative: false)];
        foreach (self::dropped($meta) as $layer) {
            $parts[] = self::LAYERS[$layer][0];
        }

        return implode('; ', $parts);
    }

    /** @param  array<string, mixed>  $meta */
    private static function auto(array $meta): string
    {
        if (($meta['rule'] ?? null) === self::RULE_OPTIONAL) {
            $supply = (int) ($meta['supply'] ?? 0);

            return 'Слот «по возможности»: на этот и следующий день '.$supply.' '
                .self::plural($supply, 'событие', 'события', 'событий').'. '.self::card($meta, comparative: true);
        }

        $dropped = self::dropped($meta);
        if ($dropped !== []) {
            return self::upperFirst(implode('; ', array_map(
                fn (string $layer) => self::LAYERS[$layer][0].' — '.self::LAYERS[$layer][1].' не нашлось',
                $dropped,
            )));
        }

        return isset($meta['closes_in']) ? self::run((int) $meta['closes_in']) : self::card($meta, comparative: true);
    }

    /**
     * «Ближайшее» верно только для выбранного: среди равных по баллу побеждает ближайшее начало.
     * Билеты решают внутри шага свежести, поэтому названы, когда есть: иначе ближе могла стоять полная карточка без них.
     *
     * @param  array<string, mixed>  $meta
     */
    private static function card(array $meta, bool $comparative): string
    {
        $score = $meta['score'] ?? null;
        if (! is_array($score)) {
            return 'Выбрано автоматически';
        }
        // выбор по интересу пишет facts; у записей, выбранных раньше, их нет
        if (is_array($meta['facts'] ?? null)) {
            return self::interest($meta['facts'], $score);
        }

        $missing = [];
        foreach (self::CARD_PARTS as $part => $word) {
            if ((int) ($score[$part] ?? 0) <= 0) {
                $missing[] = $word;
            }
        }

        $tickets = (int) ($score['tickets'] ?? 0) > 0;
        if (! $comparative) {
            $line = $missing === [] ? 'Полная карточка' : 'В карточке нет '.self::listing($missing);

            return $tickets ? $line.', билеты в продаже' : $line;
        }
        if ($missing !== []) {
            return 'Лучшее из найденного, но в карточке нет '.self::listing($missing);
        }

        return $tickets ? 'Ближайшее с полной карточкой и билетами в продаже' : 'Ближайшее с полной карточкой';
    }

    /**
     * Чем событие интересно: вид, сколько дат, касса, оценки; после точки с запятой — чего не хватает.
     *
     * @param  array<string, mixed>  $facts  EventBroadcastScorer::explain()
     * @param  array<string, mixed>  $score
     */
    private static function interest(array $facts, array $score): string
    {
        $repeats = (int) ($facts['repeats'] ?? 1);
        $rating = (int) ($facts['rating'] ?? 0);

        $good = [self::KIND_WORDS[(string) ($facts['kind'] ?? '')] ?? 'событие'];
        if (empty($facts['multi_day'])) {
            $good[] = match (true) {
                $repeats <= 1 => 'разовое',
                $repeats < EventBroadcastScorer::WEEKLY_FROM => 'несколько дат',
                default => 'идёт каждую неделю',
            };
        }
        if (! empty($facts['ticketed'])) {
            $good[] = 'билеты в кассе';
        }
        if ($rating >= EventBroadcastScorer::RATED_FROM) {
            $good[] = $rating.' '.self::plural($rating, 'оценка', 'оценки', 'оценок').' на Яндекс Афише';
        }
        if ((int) ($score['free'] ?? 0) > 0) {
            $good[] = 'бесплатное, а на неделе такого ещё нет';
        }

        $weak = [];
        foreach (self::WEAK_PARTS as $part => $word) {
            if ((int) ($score[$part] ?? 0) < 0) {
                $weak[] = $word;
            }
        }

        $line = implode(', ', $good).($weak === [] ? '' : '; '.implode(', ', $weak));
        $access = EventAccess::LABELS[(string) ($facts['access'] ?? '')] ?? null;

        return self::upperFirst($access === null ? $line : $access.' — '.$line);
    }

    private static function run(int $daysLeft): string
    {
        return 'Уже идёт и закрывается '.match (true) {
            $daysLeft <= 0 => 'в день поста',
            $daysLeft === 1 => 'на следующий день после поста',
            default => 'через '.$daysLeft.' '.self::days($daysLeft).' после поста',
        };
    }

    /** @param  array<string, mixed>  $meta */
    private static function waiting(array $meta): string
    {
        $since = self::date($meta['since'] ?? null);

        return $since === null ? 'Ждало свободного дня' : 'Ждало свободного дня с '.$since->format('d.m');
    }

    /** @param  array<string, mixed>  $meta */
    private static function hand(array $meta): string
    {
        $what = match ($meta['rule'] ?? null) {
            'move', 'day' => 'Перенесено вами',
            'restore' => 'Возвращено вами',
            'now' => 'Отправлено вами вне очереди',
            default => 'Поставлено вами',
        };
        $at = self::date($meta['at'] ?? null);

        return $at === null ? $what : $what.' '.$at->format('d.m').' в '.$at->format('H:i');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private static function dropped(array $meta): array
    {
        return array_values(array_intersect(array_keys(self::LAYERS), (array) ($meta['dropped'] ?? [])));
    }

    /** @param  list<string>  $words */
    private static function listing(array $words): string
    {
        $last = array_pop($words);

        return $words === [] ? $last : implode(', ', $words).' и '.$last;
    }

    private static function days(int $n): string
    {
        return self::plural($n, 'день', 'дня', 'дней');
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;

        return match (true) {
            $mod100 >= 11 && $mod100 <= 14 => $many,
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(self::TZ);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function upperFirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }
}
