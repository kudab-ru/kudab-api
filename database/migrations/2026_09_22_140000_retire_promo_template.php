<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Убираем форму «promo»: это не форма, а спрятанная настройка длины.
//
// От базовой она отличается ровно обрезкой — 280 символов вместо 400.
// Замер 22.09.2026: из 207 живых фраз ни одна не длиннее 280 (самая длинная
// 254), поэтому на событиях С ФРАЗОЙ тексты совпадают побайтово. Разница
// вылезает только там, где фразы нет и в пост идёт пресс-релиз: таких будущих
// событий 465, у 290 релиз длиннее 280.
//
// То есть читатель видит не другую подачу, а обрывок чуть раньше — под именем
// «Промо-анонс», по которому этого не угадать. Если длинные релизы мешают,
// правильное место — обрезка в базовой форме, а не отдельный шаблон.
//
// Не удаляем, а выключаем: строка остаётся в базе, из списка форм исчезает.
return new class extends Migration
{
    private const CODE = 'promo';

    public function up(): void
    {
        // Сначала снимаем с каналов — иначе канал остался бы с формой,
        // которой нет в списке, и владелец не понял бы, что собирает посты.
        foreach (DB::table('telegram.chat_broadcasts')->get(['id', 'settings']) as $row) {
            $settings = is_string($row->settings) ? json_decode($row->settings, true) : (array) $row->settings;
            if (! is_array($settings)) {
                continue;
            }

            $changed = false;

            if (($settings['template_code'] ?? null) === self::CODE) {
                $settings['template_code'] = 'basic';
                $changed = true;
            }

            if (! empty($settings['template_rotation']) && is_array($settings['template_rotation'])) {
                $rotation = array_values(array_unique(array_map(
                    static fn ($c) => $c === self::CODE ? 'basic' : $c,
                    $settings['template_rotation'],
                )));

                if ($rotation !== $settings['template_rotation']) {
                    $settings['template_rotation'] = $rotation;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('telegram.chat_broadcasts')->where('id', $row->id)->update([
                    'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('telegram.message_templates')
            ->where('code', self::CODE)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('telegram.message_templates')
            ->where('code', self::CODE)
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
