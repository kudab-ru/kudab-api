<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Единый список источников для админки: по строке на источник.
 *
 * ЗАЧЕМ. Раньше источниками управляли с двух разных страниц: сайты на одной,
 * Я.Афиша на другой, а ВКонтакте — вообще нигде, хотя по числу карточек он
 * первый. Владелец сказал про страницу Я.Афиши «мало что понятно»: там тумблер,
 * два поля лимитов и список разделов латиницей, но нет ответа на единственный
 * важный вопрос — работает ли и сколько приносит.
 *
 * ЕДИНИЦА СПИСКА — ИСТОЧНИК, А НЕ РАЗДЕЛ. Разделу нечем заполнить строку:
 * в source_runs нет колонки section, все заходы Я.Афиши пишутся под одним
 * именем, поэтому у строки «Я.Афиша — театр» не было бы ни статуса, ни времени
 * сбора. Разложить события по разделам тоже нечем.
 *
 * СЧИТАЕМ КАРТОЧКИ, А НЕ СОБЫТИЯ. Пять сеансов одного спектакля — это одна
 * карточка: та же формула, что уже стоит на странице площадки. Разница не
 * косметическая: у Я.Афиши 278 строк против 111 карточек. Из первой пары
 * владелец сделает вывод «сайты не нужны», из второй — обратный.
 */
final class SourceOverview
{
    /** lastRuns() зовут четыре ветки — считаем один раз. */
    private ?array $runsCache = null;

    /** Сети, где источник — это множество сообществ, а не одна настройка. */
    private const AGGREGATE_NETWORKS = [
        1 => 'ВКонтакте',
        2 => 'Телеграм',
    ];

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        $cards = $this->cardsByLink();
        $rows = array_merge(
            $this->siteRows($cards),
            $this->configRows($cards),
            $this->builtinRows($cards),
            $this->aggregateRows($cards),
        );

        usort($rows, static fn ($a, $b) => $b['cards_ahead'] <=> $a['cards_ahead']);

        return $rows;
    }

    /**
     * Карточки по каждой ссылке-источнику. Привязка идёт через event_sources:
     * это единственный путь, работающий одинаково для сайта, Я.Афиши и ВК.
     *
     * @return array<int,array{ahead:int,d30:int,last_event_at:?string}>
     */
    private function cardsByLink(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT es.social_link_id AS link_id,
                   COUNT(DISTINCT COALESCE(e.event_group_id::text, 'e' || e.id))
                       FILTER (WHERE e.start_time > now()) AS ahead,
                   COUNT(DISTINCT COALESCE(e.event_group_id::text, 'e' || e.id))
                       FILTER (WHERE e.created_at >= now() - interval '30 days') AS d30,
                   MAX(e.created_at) AS last_event_at
            FROM events e
            JOIN event_sources es ON es.event_id = e.id
            WHERE e.deleted_at IS NULL
            GROUP BY es.social_link_id
        SQL);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->link_id] = [
                'ahead' => (int) $r->ahead,
                'd30' => (int) $r->d30,
                'last_event_at' => self::iso($r->last_event_at),
            ];
        }

        return $out;
    }

    /** Последний ЗАВЕРШЁННЫЙ заход по каждому slug профиля/конфига. */
    private function lastRuns(): array
    {
        if ($this->runsCache !== null) {
            return $this->runsCache;
        }

        $out = [];
        foreach (DB::select('SELECT source_slug, MAX(finished_at) AS last FROM source_runs WHERE finished_at IS NOT NULL GROUP BY source_slug') as $r) {
            $out[(string) $r->source_slug] = self::iso($r->last);
        }

        return $this->runsCache = $out;
    }

    /**
     * Время всегда с зоной. Postgres отдаёт «2026-09-21 09:12:33» без смещения,
     * и браузер читает это как МЕСТНОЕ время: на странице «3 часа назад»
     * превращалось в «6 часов назад», а источник, собиравший 47 часов назад,
     * перескакивал в «молчит». Одна и та же строка, прочитанная сервером и
     * браузером по-разному, — источник и расхождения при гидратации.
     */
    private static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value, 'UTC')->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Сайты-источники: строка на профиль. */
    private function siteRows(array $cards): array
    {
        $runs = $this->lastRuns();
        $links = DB::table('community_social_links')
            ->where('social_network_id', 3)
            ->pluck('id', 'external_community_id');

        $out = [];
        foreach (DB::table('source_profiles')->orderBy('name')->get() as $p) {
            $linkId = (int) ($links[$p->slug] ?? 0);
            $c = $cards[$linkId] ?? ['ahead' => 0, 'd30' => 0, 'last_event_at' => null];

            $out[] = $this->row(
                key: 'site:'.$p->slug,
                name: (string) $p->name,
                kindLabel: ((string) ($p->parse_mode ?? 'jsonld')) === 'llm_text' ? 'Сайт (через ИИ)' : 'Сайт',
                enabled: (bool) $p->enabled,
                cards: $c,
                lastCollectedAt: $runs[$p->slug] ?? null,
                url: (string) $p->listing_url,
                profileId: (int) $p->id,
            );
        }

        return $out;
    }

    /** Я.Афиша и qtickets: строка на город-конфиг. */
    private function configRows(array $cards): array
    {
        $runs = $this->lastRuns();
        $names = ['yandex_afisha' => 'Яндекс.Афиша', 'qtickets' => 'Qtickets'];
        $netBySlug = ['yandex_afisha' => 4, 'qtickets' => 5];

        $out = [];
        foreach (DB::table('source_configs')->orderBy('source_slug')->get() as $cfg) {
            $slug = (string) $cfg->source_slug;
            $linkId = (int) DB::table('community_social_links')
                ->where('social_network_id', $netBySlug[$slug] ?? 0)
                ->value('id');
            $c = $cards[$linkId] ?? ['ahead' => 0, 'd30' => 0, 'last_event_at' => null];

            $sections = $this->sections($cfg);

            $out[] = $this->row(
                key: $slug.':'.$cfg->city_slug,
                name: $names[$slug] ?? $slug,
                kindLabel: 'Афиша-агрегатор',
                enabled: (bool) $cfg->enabled,
                cards: $c,
                lastCollectedAt: $runs[$slug] ?? null,
                url: null,
                sections: $sections,
            );
        }

        return $out;
    }

    /**
     * Qtickets: встроенный источник без строки в source_configs. Без этой
     * ветки он пропадал из списка вовсе, хотя события даёт.
     */
    private function builtinRows(array $cards): array
    {
        $linkId = (int) DB::table('community_social_links')->where('social_network_id', 5)->value('id');
        if ($linkId === 0) {
            return [];
        }

        $c = $cards[$linkId] ?? ['ahead' => 0, 'd30' => 0, 'last_event_at' => null];

        // Журнала заходов у встроенного источника нет: парсер пишет в
        // source_runs только Я.Афишу и профили сайтов. Без запасного варианта
        // строка выходила «не запускался» рядом с ненулевым числом карточек —
        // владелец видит противоречие и перестаёт верить плашкам.
        return [$this->row(
            key: 'qtickets',
            name: 'Qtickets',
            kindLabel: 'Афиша-агрегатор',
            enabled: true,
            cards: $c,
            lastCollectedAt: $this->lastRuns()['qtickets'] ?? $c['last_event_at'],
            url: null,
            manageable: false,
        )];
    }

    /** @return list<string> */
    private function sections(object $cfg): array
    {
        $raw = $cfg->sections ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        // В конфиге раздел — это объект {slug, enabled}; выключенные не
        // показываем: владельцу важно, что источник собирает СЕЙЧАС.
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                if (($item['enabled'] ?? true) && isset($item['slug'])) {
                    $out[] = (string) $item['slug'];
                }

                continue;
            }
            $out[] = (string) $item;
        }

        return $out;
    }

    /**
     * ВКонтакте и Телеграм — одной строкой на сеть.
     *
     * Показать их отдельными строками нельзя: только у ВК 117 сообществ, и
     * страница превратилась бы в список пабликов. Но и молчать про них нельзя —
     * по карточкам ВК идёт первым, и страница без него врёт о том, откуда
     * берутся события. Управление сообществами живёт на своей странице,
     * поэтому тумблера здесь нет.
     */
    private function aggregateRows(array $cards): array
    {
        $out = [];
        foreach (self::AGGREGATE_NETWORKS as $netId => $label) {
            $links = DB::table('community_social_links as l')
                ->join('communities as c', 'c.id', '=', 'l.community_id')
                ->join('cities as ci', 'ci.id', '=', 'c.city_id')
                ->where('l.social_network_id', $netId)
                ->where('ci.status', 'active')
                ->pluck('l.id')->all();

            if ($links === []) {
                continue;
            }

            $ahead = 0;
            $d30 = 0;
            $lastEvent = null;
            foreach ($links as $id) {
                $c = $cards[(int) $id] ?? null;
                if ($c === null) {
                    continue;
                }
                $ahead += $c['ahead'];
                $d30 += $c['d30'];
                if ($c['last_event_at'] !== null && ($lastEvent === null || $c['last_event_at'] > $lastEvent)) {
                    $lastEvent = $c['last_event_at'];
                }
            }

            $lastPost = self::iso(DB::table('context_posts')
                ->whereIn('social_link_id', $links)
                ->max('created_at'));

            $out[] = $this->row(
                key: 'net:'.$netId,
                name: $label,
                kindLabel: 'Сообщества',
                enabled: true,
                cards: ['ahead' => $ahead, 'd30' => $d30, 'last_event_at' => $lastEvent],
                lastCollectedAt: $lastPost,
                url: null,
                manageable: false,
                communities: count($links),
            );
        }

        return $out;
    }

    /**
     * Состояние источника ЧЕЛОВЕЧЕСКИМИ словами.
     *
     * Выключенный источник больше не бывает «здоровым»: прежний светофор
     * смотрел только на последние заходы и не смотрел на тумблер, поэтому
     * выключенный сайт со старыми удачными заходами горел зелёным. Это ровно
     * та ложь, из-за которой страницу и переделывали.
     */
    private function state(bool $enabled, array $cards, ?string $lastCollectedAt): array
    {
        if (! $enabled) {
            return ['off', 'Выключен — события не собираются'];
        }
        if ($lastCollectedAt === null) {
            return ['new', 'Ещё ни разу не собирал'];
        }

        $hoursAgo = (time() - strtotime($lastCollectedAt)) / 3600;
        if ($hoursAgo > 48) {
            return ['quiet', 'Молчит больше двух суток'];
        }
        if ($cards['d30'] === 0) {
            return ['empty', 'Собирает, но событий не даёт'];
        }

        return ['ok', 'Работает'];
    }

    private function row(
        string $key,
        string $name,
        string $kindLabel,
        bool $enabled,
        array $cards,
        ?string $lastCollectedAt,
        ?string $url = null,
        ?int $profileId = null,
        array $sections = [],
        bool $manageable = true,
        ?int $communities = null,
    ): array {
        [$state, $note] = $this->state($enabled, $cards, $lastCollectedAt);

        return [
            'key' => $key,
            'name' => $name,
            'kind_label' => $kindLabel,
            'enabled' => $enabled,
            'manageable' => $manageable,
            'cards_ahead' => $cards['ahead'],
            'cards_30d' => $cards['d30'],
            'last_collected_at' => $lastCollectedAt,
            'last_event_at' => $cards['last_event_at'],
            'state' => $state,
            'state_note' => $note,
            'url' => $url,
            'profile_id' => $profileId,
            'sections' => $sections,
            'communities' => $communities,
        ];
    }
}
