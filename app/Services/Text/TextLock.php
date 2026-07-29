<?php

declare(strict_types=1);

namespace App\Services\Text;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Отметка «это поле правил человек» в `text_meta.lock`. Читает её парсер
 * (App\Services\Text\TextProvenance) и такие поля больше не переписывает.
 *
 * Ставится ТОЛЬКО отсюда — из явного действия админа. Расхождение хеша текста
 * блокировкой не считается: описание регулярно перезаписывает сам пайплайн
 * (EventUpsertJob берёт более длинное входящее), и трактовать это как ручную
 * правку значило бы молча выключить генерацию для половины каталога.
 *
 * Снятие — правкой поля пустым значением: писать «ничего» руками незачем,
 * зато это понятный способ вернуть текст движку.
 */
final class TextLock
{
    public static function apply(string $table, int $id, array $editedFields): void
    {
        $fields = array_values(array_intersect(
            array_keys(array_filter($editedFields, fn ($v) => $v !== null && $v !== '')),
            self::lockable(),
        ));
        $released = array_values(array_intersect(
            array_keys(array_filter($editedFields, fn ($v) => $v === null || $v === '')),
            self::lockable(),
        ));

        if ($fields === [] && $released === []) {
            return;
        }

        try {
            if (! Schema::hasColumn($table, 'text_meta')) {
                return;
            }

            $row = DB::table($table)->where('id', $id)->first(['text_meta']);
            if ($row === null) {
                return;
            }

            $meta = self::decode($row->text_meta);
            $lock = array_values(array_unique(array_merge(
                array_diff((array) ($meta['lock'] ?? []), $released),
                $fields,
            )));

            if ($lock === []) {
                unset($meta['lock']);
            } else {
                $meta['lock'] = $lock;
            }

            DB::table($table)->where('id', $id)->update([
                'text_meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) {
            // отметка о ручной правке не тот повод, чтобы валить сохранение формы
        }
    }

    /** @return list<string> поля, которые пишет LLM и может перехватить человек */
    private static function lockable(): array
    {
        return ['description', 'short_description', 'tg_description', 'tg_portrait'];
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        $decoded = is_string($meta) ? json_decode($meta, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
