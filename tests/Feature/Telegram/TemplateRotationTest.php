<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChatBroadcast;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Чередование форм поста.
 *
 * Владелец: «чередуем описание сверху с описанием после сведений». Это два
 * разных способа чтения: в первом сначала читается СОБЫТИЕ, во втором — «где и
 * когда». Прежние три шаблона чередовать было нечем — они отличались только
 * степенью обрезки, и читатель увидел бы не разнообразие, а пропавший текст.
 *
 * Форма выбирается по НОМЕРУ ПОСТА, а не случайно: пересборка подписи (правка
 * текста, перенос на другой день) не должна менять вид поста.
 */
class TemplateRotationTest extends TestCase
{
    private function broadcast(array $settings): TelegramChatBroadcast
    {
        $b = new TelegramChatBroadcast;
        $b->settings = $settings;

        return $b;
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' 12:00', 'Europe/Moscow');
    }

    public function test_without_rotation_the_single_form_is_used(): void
    {
        $b = $this->broadcast(['template_code' => 'basic']);

        $this->assertSame('basic', $b->templateCodeForDate($this->day('2026-09-22')));
        $this->assertSame('basic', $b->templateCodeForDate($this->day('2026-09-23')));
    }

    /** Ради этого всё: соседние ДНИ выходят разными формами. */
    public function test_forms_alternate_between_days(): void
    {
        $b = $this->broadcast([
            'template_code' => 'basic',
            'template_rotation' => ['basic', 'lead-below'],
        ]);

        $this->assertNotSame(
            $b->templateCodeForDate($this->day('2026-09-22')),
            $b->templateCodeForDate($this->day('2026-09-23')),
        );
        $this->assertSame(
            $b->templateCodeForDate($this->day('2026-09-22')),
            $b->templateCodeForDate($this->day('2026-09-24')),
        );
    }

    /** Весь день — одной формой: утренний и вечерний посты совпадают. */
    public function test_all_posts_of_one_day_share_the_form(): void
    {
        $b = $this->broadcast(['template_rotation' => ['basic', 'lead-below']]);

        $morning = CarbonImmutable::parse('2026-09-22 10:00', 'Europe/Moscow');
        $evening = CarbonImmutable::parse('2026-09-22 19:00', 'Europe/Moscow');

        $this->assertSame($b->templateCodeForDate($morning), $b->templateCodeForDate($evening));
    }

    /** На стыке месяцев повтора нет: считаем сутки, а не день месяца. */
    public function test_month_boundary_keeps_alternating(): void
    {
        $b = $this->broadcast(['template_rotation' => ['basic', 'lead-below']]);

        $this->assertNotSame(
            $b->templateCodeForDate($this->day('2026-09-30')),
            $b->templateCodeForDate($this->day('2026-10-01')),
        );
    }

    public function test_three_forms_rotate_too(): void
    {
        $b = $this->broadcast(['template_rotation' => ['a', 'b', 'c']]);

        $got = array_map(
            fn (string $d) => $b->templateCodeForDate($this->day($d)),
            ['2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'],
        );

        $this->assertSame($got[0], $got[3]);
        $this->assertSame(3, count(array_unique(array_slice($got, 0, 3))));
    }

    /** Мусор в настройке не ломает пост: падаем на единственную форму. */
    public function test_broken_rotation_falls_back(): void
    {
        $this->assertSame('basic', $this->broadcast([
            'template_code' => 'basic',
            'template_rotation' => 'не массив',
        ])->templateCodeForDate($this->day('2026-09-22')));

        $this->assertSame('basic', $this->broadcast([
            'template_code' => 'basic',
            'template_rotation' => ['', '  '],
        ])->templateCodeForDate($this->day('2026-09-22')));
    }

    /** Без даты (черновик, предпросмотр) — форма по умолчанию. */
    public function test_no_date_means_default_form(): void
    {
        $b = $this->broadcast(['template_code' => 'basic', 'template_rotation' => ['basic', 'lead-below']]);

        $this->assertSame('basic', $b->templateCodeForDate(null));
    }
}
