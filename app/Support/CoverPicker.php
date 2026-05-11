<?php

namespace App\Support;

/**
 * Эвристический выбор лучшей обложки события из набора URL картинок.
 *
 * Используется в EventRepository::hydrateImages() — переставляет массив URL
 * так, чтобы первая картинка была наиболее «карточной»: правильное соотношение
 * сторон, достаточно крупная, не текстовая. Вторая, третья — следующие по
 * качеству.
 *
 * Скоринг:
 * - VK URL формата `*.userapi.com/...?crop=X,Y,W,H&as=...,1280x720`:
 *   используем `crop` для реальных размеров и aspect ratio.
 *   Идеал — 4:3 / 16:9 / 3:4 (горизонталь/вертикаль). Очень узкое/широкое (баннеры) штрафуем.
 * - VK URL без `crop` или другой источник (TG/site): нейтральный score, сохраняется
 *   относительный порядок входа.
 *
 * Алгоритм стабилен: при равном score сохраняется исходный порядок.
 */
final class CoverPicker
{
    /**
     * Возвращает массив URL, отсортированный по убыванию «обложечного» score.
     * Не дедупит — это уже сделано выше в hydrateImages.
     *
     * @param string[] $urls
     * @return string[]
     */
    public static function pickBest(array $urls): array
    {
        if (count($urls) <= 1) return array_values($urls);

        $scored = [];
        foreach ($urls as $i => $u) {
            $scored[] = [
                'i'     => $i,
                'url'   => $u,
                'score' => self::scoreUrl($u),
            ];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['i'] <=> $b['i']; // стабильность
            }
            return $b['score'] <=> $a['score']; // убывание
        });

        return array_map(fn ($r) => (string) $r['url'], $scored);
    }

    /**
     * Score в условных единицах. Чем больше — тем лучше.
     * Возвращаемый интервал — примерно [0..120].
     */
    public static function scoreUrl(string $url): int
    {
        $url = trim($url);
        if ($url === '') return 0;

        // VK userapi: достаём crop для реальных пропорций
        if (str_contains($url, 'userapi.com') || str_contains($url, 'vkuseraudio.net')) {
            return self::scoreVk($url);
        }

        // Telegram CDN / site / другие — нейтральный фолбэк
        return 50;
    }

    private static function scoreVk(string $url): int
    {
        $score = 50;

        // 1) crop=x,y,w,h → точное соотношение сторон фото
        if (preg_match('~[?&]crop=(\d+),(\d+),(\d+),(\d+)~', $url, $m)) {
            $w = (int) $m[3];
            $h = (int) $m[4];

            if ($w > 0 && $h > 0) {
                $score += self::aspectScore($w, $h);
                $score += self::sizeScore($w, $h);
            }
        } elseif (preg_match('~[?&]as=([^&]+)~', $url, $m)) {
            // нет crop — возьмём максимальный as=...,WxH из набора
            $best = self::parseAsMaxSize($m[1]);
            if ($best !== null) {
                [$w, $h] = $best;
                $score += self::aspectScore($w, $h);
                $score += self::sizeScore($w, $h);
            }
        }

        return max(0, $score);
    }

    /**
     * Бонус за «карточное» соотношение сторон.
     *
     * - 4:3 (1.33) и 16:9 (1.78) горизонталь — идеал для feed-карточек.
     * - 1:1 / 3:4 / 9:16 — норм.
     * - >2.4 (баннер) или <0.4 (узкая полоса) — штраф.
     */
    private static function aspectScore(int $w, int $h): int
    {
        $r = $w / $h; // >1 — горизонталь, <1 — вертикаль

        // штраф за узкие баннеры (часто это тексто-афиши шапки сообществ)
        if ($r > 2.4 || $r < 1.0 / 2.4) return -25;

        // идеал
        if ($r >= 1.25 && $r <= 1.85) return 30;       // 4:3 .. 16:9 horizontal
        if ($r <= 1.0 / 1.25 && $r >= 1.0 / 1.85) return 25; // 3:4 .. 9:16 portrait

        // близко к квадрату — приемлемо
        if ($r >= 0.85 && $r <= 1.15) return 15;

        // в промежутке между квадратом и крайностями — мягкий бонус
        return 5;
    }

    /**
     * Бонус за размер (большие фото лучше; тайлы 240x180 — плохо).
     */
    private static function sizeScore(int $w, int $h): int
    {
        $area = $w * $h;
        if ($area >= 1280 * 720) return 30;
        if ($area >= 800 * 600)  return 20;
        if ($area >= 480 * 360)  return 10;
        if ($area >= 240 * 180)  return 0;
        return -10;
    }

    /**
     * Парсит as=32x18,48x27,...,1280x720 → возвращает [W, H] максимального размера.
     * @return array{0:int,1:int}|null
     */
    private static function parseAsMaxSize(string $as): ?array
    {
        $best = null;
        $bestArea = -1;

        foreach (explode(',', $as) as $tok) {
            $tok = trim($tok);
            if ($tok === '' || !preg_match('~^(\d+)x(\d+)$~', $tok, $m)) continue;

            $w = (int) $m[1];
            $h = (int) $m[2];
            $area = $w * $h;
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = [$w, $h];
            }
        }

        return $best;
    }
}
