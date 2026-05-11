<?php

namespace Tests\Unit;

use App\Support\CoverPicker;
use PHPUnit\Framework\TestCase;

class CoverPickerTest extends TestCase
{
    /* ====== scoreUrl: VK с crop ====== */

    public function test_vk_landscape_16_9_high_score(): void
    {
        $url = 'https://sun9-1.userapi.com/img.jpg?crop=0,0,1280,720';
        $score = CoverPicker::scoreUrl($url);

        // 50 base + 30 aspect (16:9) + 30 size (>=1280*720) = 110
        $this->assertGreaterThanOrEqual(100, $score);
    }

    public function test_vk_portrait_3_4_decent_score(): void
    {
        $url = 'https://sun9-1.userapi.com/img.jpg?crop=0,0,720,960';
        $score = CoverPicker::scoreUrl($url);

        // 50 + 25 (portrait sweet spot) + 20 (800*600 area)
        $this->assertGreaterThanOrEqual(80, $score);
    }

    public function test_vk_extreme_banner_penalized(): void
    {
        // 1230x200 — широкая полоса, баннер
        $url = 'https://sun9-1.userapi.com/img.jpg?crop=0,0,1230,200';
        $score = CoverPicker::scoreUrl($url);

        // штраф за aspect, но size может дать чуть-чуть
        $banner   = $score;
        $normal   = CoverPicker::scoreUrl('https://sun9-1.userapi.com/img.jpg?crop=0,0,1280,720');
        $this->assertLessThan($normal, $banner);
    }

    public function test_vk_tiny_thumbnail_low_score(): void
    {
        $url = 'https://sun9-1.userapi.com/img.jpg?crop=0,0,200,150';
        $score = CoverPicker::scoreUrl($url);

        // маленькая, штраф за size
        $this->assertLessThan(80, $score);
    }

    /* ====== scoreUrl: VK через as= ====== */

    public function test_vk_uses_as_when_no_crop(): void
    {
        $url = 'https://sun9-1.userapi.com/img.jpg?quality=95&as=32x18,160x90,1280x720';
        $score = CoverPicker::scoreUrl($url);

        // должна найти 1280x720 в as= и дать высокий score
        $this->assertGreaterThanOrEqual(100, $score);
    }

    /* ====== scoreUrl: не-VK ====== */

    public function test_unknown_host_neutral(): void
    {
        $score = CoverPicker::scoreUrl('https://t.me/xxx/photo/abcdef.jpg');
        $this->assertSame(50, $score);
    }

    public function test_empty_url_zero(): void
    {
        $this->assertSame(0, CoverPicker::scoreUrl(''));
        $this->assertSame(0, CoverPicker::scoreUrl('   '));
    }

    /* ====== pickBest ====== */

    public function test_pick_best_orders_landscape_before_banner(): void
    {
        $banner    = 'https://sun9-1.userapi.com/img.jpg?crop=0,0,1230,200';
        $landscape = 'https://sun9-1.userapi.com/img.jpg?crop=0,0,1280,720';

        $sorted = CoverPicker::pickBest([$banner, $landscape]);
        $this->assertSame($landscape, $sorted[0]);
        $this->assertSame($banner,    $sorted[1]);
    }

    public function test_pick_best_stable_for_equal_scores(): void
    {
        // Две Telegram-ссылки одинакового score — должен сохранить исходный порядок
        $a = 'https://t.me/a/photo.jpg';
        $b = 'https://t.me/b/photo.jpg';

        $this->assertSame([$a, $b], CoverPicker::pickBest([$a, $b]));
        $this->assertSame([$b, $a], CoverPicker::pickBest([$b, $a]));
    }

    public function test_pick_best_single_or_empty(): void
    {
        $this->assertSame([], CoverPicker::pickBest([]));
        $this->assertSame(['x'], CoverPicker::pickBest(['x']));
    }

    public function test_pick_best_real_vk_urls(): void
    {
        // Реальный кейс: первая картинка — текстовый баннер 1230x200,
        // вторая — нормальное горизонтальное фото 1230x692.
        // Ожидание: фото станет первым.
        $banner = 'https://sun9-1.userapi.com/s/v1/ig2/abc.jpg?quality=95&crop=0,0,1230,200&as=32x5,1280x208';
        $photo  = 'https://sun9-2.userapi.com/s/v1/ig2/def.jpg?quality=95&crop=0,0,1230,692&as=32x18,1280x719';

        $sorted = CoverPicker::pickBest([$banner, $photo]);
        $this->assertSame($photo, $sorted[0]);
    }

    public function test_pick_best_keeps_landscape_over_portrait(): void
    {
        // 16:9 должно быть выше чем 3:4 (для feed-карточек горизонталь предпочтительнее)
        $portrait  = 'https://sun9-1.userapi.com/img.jpg?crop=0,0,720,960';
        $landscape = 'https://sun9-1.userapi.com/img.jpg?crop=0,0,1280,720';

        $sorted = CoverPicker::pickBest([$portrait, $landscape]);
        $this->assertSame($landscape, $sorted[0]);
    }
}
