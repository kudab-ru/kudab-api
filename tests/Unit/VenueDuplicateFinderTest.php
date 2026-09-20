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
    ): object {
        return (object) [
            'id' => $id,
            'city_id' => 1,
            'name' => $name,
            'kind' => null,
            'address' => $address,
            'latitude' => $lat,
            'longitude' => $lon,
            'house_fias_id' => null,
            'source_meta' => json_encode($legal ? ['legal_address' => true] : ['origin' => 'osm']),
            'events_count' => $events,
        ];
    }
}
