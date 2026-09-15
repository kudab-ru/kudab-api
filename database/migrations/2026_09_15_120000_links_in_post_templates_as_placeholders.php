<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ссылки в шаблонах постов — плейсхолдерами, а не тегом руками.
 *
 * Вторую ссылку («Открыть оригинал») дописывал код уже после рендера шаблона:
 * в редакторе её не было видно, подвинуть или убрать нельзя. Теперь строка
 * живёт в самом шаблоне, а {original_link} исчезает целиком у события без
 * источника — голый {canonical_url} в теге оставил бы пустой href.
 *
 * Вывод не меняется: проверено побайтовой сверкой поста до и после.
 */
return new class extends Migration
{
    private const PATTERN = '~<a href="\{url\}">Подробнее на kudab\.ru\s*→?</a>~u';

    private const REPLACEMENT = '{more_link}          {original_link}';

    public function up(): void
    {
        foreach (DB::table('telegram.message_templates')->get(['id', 'body']) as $row) {
            $body = preg_replace(self::PATTERN, self::REPLACEMENT, (string) $row->body, -1, $count);
            if ($count > 0) {
                DB::table('telegram.message_templates')->where('id', $row->id)->update(['body' => $body]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('telegram.message_templates')->get(['id', 'body']) as $row) {
            $body = str_replace(
                self::REPLACEMENT,
                '<a href="{url}">Подробнее на kudab.ru →</a>',
                (string) $row->body,
            );
            if ($body !== $row->body) {
                DB::table('telegram.message_templates')->where('id', $row->id)->update(['body' => $body]);
            }
        }
    }
};
