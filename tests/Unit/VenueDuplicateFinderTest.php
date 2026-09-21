<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\VenueDuplicateFinder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Пришпилено к РЕАЛЬНОМУ каталогу прода (снято 20.09.2026 через read-only шлюз).
 *
 * Смысл теста не в алгоритме, а в конкретных парах: именно на них видно, что
 * находилка ловит настоящие дубли и не трогает похожие, но разные места.
 * Никитинский театр и Дом-музей Никитина — вложенные имена, и если гейт по
 * расстоянию сломается, они склеятся, а это две разные точки города.
 */
class VenueDuplicateFinderTest extends TestCase
{
    /** Каталог с прода, координаты и адреса подлинные. */
    private function catalog(): Collection
    {
        return collect([
            // Дивногорье: офис из ЕГРЮЛ в центре против самого заповедника в 78 км
            $this->v(57, 'Музей-заповедник «Дивногорье»', 51.6660, 39.1882, 'ул Кольцовская, 56А', 30, legal: true),
            $this->v(82, 'Музей-заповедник «Дивногорье»', 50.9629, 39.2970, 'Лискинский р-н, хутор Дивногорье', 33),

            // Костенки: три карточки, из них одна с именем, собранным из адреса
            $this->v(83, 'Музей-заповедник «Костенки»', 51.3858, 39.0516, 'Хохольский р-н, село Костенки', 54),
            $this->v(90, 'Музей-заповедник «Костенки»', 51.6903, 39.1636, 'Ясный проезд, 2', 11, legal: true),

            // Похожи, но разные: театр на Бакунина и его вторая сцена на Фридриха,
            // плюс дом-музей поэта. Вложенные имена, далёкие точки.
            $this->v(8, 'Никитинский театр', 51.6685, 39.1902, 'ул Бакунина', 33),
            $this->v(134, 'Прогресс. Никитинский театр', 51.6656, 39.1986, 'ул Фридриха Энгельса', 9),
            $this->v(63, 'Дом-музей И.С. Никитина', 51.6669, 39.1999, 'Никитинская, 19А', 0),

            // Филиалы одной сети — самостоятельные площадки на разных улицах
            $this->v(101, 'Quest brothers на Невского', 51.7096, 39.1516, 'ул. Владимира Невского', 558),
            $this->v(102, 'Quest Brothers на Московском', 51.6890, 39.1846, 'Московский проспект', 310),
        ]);
    }

    public function test_divnogorye_office_and_reserve_are_paired(): void
    {
        $pair = $this->find(57, 82);

        $this->assertNotNull($pair, 'две карточки Дивногорья обязаны попасть в кандидаты');
        $this->assertSame('same_name', $pair['reason']);
        $this->assertSame(63, $pair['events_at_stake']);
        // Офис из ЕГРЮЛ — та сторона, которую предлагаем слить
        $this->assertSame(57, $pair['suggest_merge_id']);
    }

    public function test_kostenki_city_card_is_paired_with_the_village(): void
    {
        $pair = $this->find(83, 90);

        $this->assertNotNull($pair);
        $this->assertSame(90, $pair['suggest_merge_id'], 'Ясный проезд — юрадрес, село настоящее');
    }

    /** Две разные сцены одного театра склеивать нельзя. */
    public function test_two_stages_of_nikitinsky_are_not_paired(): void
    {
        $this->assertNull($this->find(8, 134));
    }

    /** Дом-музей поэта — не театр его имени, хотя имена вложены. */
    public function test_poets_house_museum_is_not_paired_with_theatre(): void
    {
        $this->assertNull($this->find(8, 63));
        $this->assertNull($this->find(134, 63));
    }

    /** Филиалы сети на разных улицах — самостоятельные площадки. */
    public function test_quest_branches_on_different_streets_are_not_paired(): void
    {
        $this->assertNull($this->find(101, 102));
    }

    public function test_confident_pairs_come_first(): void
    {
        $pairs = VenueDuplicateFinder::pairs($this->catalog());

        $this->assertNotEmpty($pairs);
        $this->assertSame('same_name', $pairs[0]['reason']);
    }

    /** Одинаковое место с разными адресными строками ловится по расстоянию. */
    public function test_nested_name_within_150m_is_paired(): void
    {
        $near = collect([
            $this->v(74, 'Попкорн Драма', 51.6685, 39.2099, 'ул. Пятницкого', 10),
            $this->v(118, 'Попкорн Драма на Пятницкого', 51.6684, 39.2098, 'Пятницкого, 52, кв. 14', 4),
        ]);

        $pairs = VenueDuplicateFinder::pairs($near);

        $this->assertCount(1, $pairs);
        $this->assertSame('nested_name', $pairs[0]['reason']);
        $this->assertLessThanOrEqual(150, $pairs[0]['distance_m']);
    }

    public function test_normalize_strips_quotes_and_case(): void
    {
        $this->assertSame(
            VenueDuplicateFinder::normalize('Музей-заповедник «Дивногорье»'),
            VenueDuplicateFinder::normalize('музей заповедник дивногорье'),
        );
    }

    /** @return array<string, mixed>|null */
    private function find(int $a, int $b): ?array
    {
        foreach (VenueDuplicateFinder::pairs($this->catalog()) as $p) {
            $ids = [$p['a']['id'], $p['b']['id']];
            if (in_array($a, $ids, true) && in_array($b, $ids, true)) {
                return $p;
            }
        }

        return null;
    }

    private function v(
        int $id,
        string $name,
        float $lat,
        float $lon,
        string $address,
        int $events,
        bool $legal = false,
        ?int $parentId = null,
        array $notDuplicateOf = [],
    ): object {
        $meta = $legal ? ['legal_address' => true] : ['origin' => 'osm'];
        if ($notDuplicateOf !== []) {
            $meta['not_duplicate_of'] = $notDuplicateOf;
        }

        return (object) [
            'id' => $id,
            'city_id' => 1,
            'name' => $name,
            'kind' => null,
            'address' => $address,
            'latitude' => $lat,
            'longitude' => $lon,
            'house_fias_id' => null,
            'parent_id' => $parentId,
            'source_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'events_count' => $events,
        ];
    }

    /** Пара, снятая человеком, больше не кандидат — и неважно, с какой стороны помечена. */
    public function test_dismissed_pair_disappears(): void
    {
        $before = collect([
            $this->v(1, 'Дом культуры «Заря»', 51.60, 39.20, 'ул. А', 5),
            $this->v(2, 'Дом культуры «Заря»', 51.6001, 39.2001, 'ул. Б', 3),
        ]);
        $this->assertCount(1, VenueDuplicateFinder::pairs($before));

        $after = collect([
            $this->v(1, 'Дом культуры «Заря»', 51.60, 39.20, 'ул. А', 5, notDuplicateOf: [2]),
            $this->v(2, 'Дом культуры «Заря»', 51.6001, 39.2001, 'ул. Б', 3),
        ]);
        $this->assertSame([], VenueDuplicateFinder::pairs($after));
    }

    /**
     * Одна точка при совсем разных именах — третья корзина.
     *
     * Живой случай прода: «Воронежский Академический Театр Драмы» (29 событий) и
     * «Театр драмы имени А. Кольцова» (2) стоят на одной координате, но их имена
     * не пересекаются ни одним значимым словом, и обе корзины выше их не видели.
     * Такой дубль незаметен вообще ничем, кроме совпадения места.
     */
    public function test_same_point_different_names_is_paired(): void
    {
        $pairs = VenueDuplicateFinder::pairs(collect([
            $this->v(32, 'Воронежский Академический Театр Драмы', 51.6637, 39.2048, 'пр-кт Революции, 55', 29),
            $this->v(64, 'Театр драмы имени А. Кольцова', 51.6637, 39.2048, 'пр-кт Революции, 55', 2),
        ]));

        $this->assertCount(1, $pairs);
        $this->assertSame('same_point', $pairs[0]['reason']);
        $this->assertSame(0, $pairs[0]['distance_m']);
    }

    /** Дальше тридцати метров — уже соседи, а не одно место. */
    public function test_far_apart_different_names_are_not_paired(): void
    {
        $pairs = VenueDuplicateFinder::pairs(collect([
            $this->v(1, 'Первое место', 51.6600, 39.2000, 'ул. А', 5),
            $this->v(2, 'Второе место', 51.6610, 39.2000, 'ул. Б', 5), // ~111 м
        ]));

        $this->assertSame([], $pairs);
    }

    /** Порядок корзин: уверенное имя выше вложенного, вложенное выше просто точки. */
    public function test_buckets_are_ordered_by_confidence(): void
    {
        $pairs = VenueDuplicateFinder::pairs(collect([
            $this->v(1, 'Дом культуры «Заря»', 51.60, 39.20, 'ул. А', 1),
            $this->v(2, 'Дом культуры «Заря»', 51.6001, 39.2001, 'ул. Б', 1),
            $this->v(3, 'Совсем другое имя', 51.60, 39.20, 'ул. А', 99),
        ]));

        $this->assertSame('same_name', $pairs[0]['reason']);
        $this->assertSame('same_point', end($pairs)['reason']);
    }

    /** Пара не должна попасть в две корзины разом. */
    public function test_pair_appears_once(): void
    {
        $pairs = VenueDuplicateFinder::pairs(collect([
            $this->v(1, 'Попкорн Драма', 51.6685, 39.2099, 'ул. Пятницкого', 10),
            $this->v(2, 'Попкорн Драма на Пятницкого', 51.6685, 39.2099, 'Пятницкого, 52', 4),
        ]));

        $this->assertCount(1, $pairs);
        $this->assertSame('nested_name', $pairs[0]['reason'], 'вложенное имя точнее, чем просто точка');
    }

    /** Сцена внутри парка — не дубль парка: проставленный родитель снимает пару сам. */
    public function test_child_venue_is_not_a_duplicate_of_its_parent(): void
    {
        $flat = collect([
            $this->v(10, 'Зелёный театр', 51.6700, 39.1800, 'Парк Динамо', 12),
            $this->v(11, 'Зелёный театр Динамо', 51.6701, 39.1801, 'Парк Динамо', 4),
        ]);
        $this->assertCount(1, VenueDuplicateFinder::pairs($flat), 'до иерархии это выглядит дублем');

        $nested = collect([
            $this->v(10, 'Зелёный театр', 51.6700, 39.1800, 'Парк Динамо', 12),
            $this->v(11, 'Зелёный театр Динамо', 51.6701, 39.1801, 'Парк Динамо', 4, parentId: 10),
        ]);
        $this->assertSame([], VenueDuplicateFinder::pairs($nested));
    }
}
