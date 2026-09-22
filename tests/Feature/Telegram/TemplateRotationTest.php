<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChatBroadcast;
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

    public function test_without_rotation_the_single_form_is_used(): void
    {
        $b = $this->broadcast(['template_code' => 'basic']);

        $this->assertSame('basic', $b->templateCodeForItem(1));
        $this->assertSame('basic', $b->templateCodeForItem(2));
    }

    /** Ради этого всё: соседние посты выходят разными формами. */
    public function test_forms_alternate_between_posts(): void
    {
        $b = $this->broadcast([
            'template_code' => 'basic',
            'template_rotation' => ['basic', 'lead-below'],
        ]);

        $this->assertNotSame($b->templateCodeForItem(10), $b->templateCodeForItem(11));
        $this->assertSame($b->templateCodeForItem(10), $b->templateCodeForItem(12));
    }

    /** Один и тот же пост всегда одной формы — пересборка её не меняет. */
    public function test_form_is_stable_for_the_same_post(): void
    {
        $b = $this->broadcast(['template_rotation' => ['basic', 'lead-below']]);

        $this->assertSame($b->templateCodeForItem(77), $b->templateCodeForItem(77));
    }

    public function test_three_forms_rotate_too(): void
    {
        $b = $this->broadcast(['template_rotation' => ['a', 'b', 'c']]);

        $this->assertSame(['b', 'c', 'a', 'b'], [
            $b->templateCodeForItem(1),
            $b->templateCodeForItem(2),
            $b->templateCodeForItem(3),
            $b->templateCodeForItem(4),
        ]);
    }

    /** Мусор в настройке не ломает пост: падаем на единственную форму. */
    public function test_broken_rotation_falls_back(): void
    {
        $this->assertSame('basic', $this->broadcast([
            'template_code' => 'basic',
            'template_rotation' => 'не массив',
        ])->templateCodeForItem(5));

        $this->assertSame('basic', $this->broadcast([
            'template_code' => 'basic',
            'template_rotation' => ['', '  '],
        ])->templateCodeForItem(5));
    }

    /** Без номера поста (черновик, предпросмотр) — форма по умолчанию. */
    public function test_no_item_id_means_default_form(): void
    {
        $b = $this->broadcast(['template_code' => 'basic', 'template_rotation' => ['basic', 'lead-below']]);

        $this->assertSame('basic', $b->templateCodeForItem(null));
    }
}
