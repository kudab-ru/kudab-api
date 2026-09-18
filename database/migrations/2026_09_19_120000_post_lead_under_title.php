<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Живая фраза — второй строкой поста, а не пятой. И строка тегов, которой нет.
 *
 * ЧТО БЫЛО. Порядок строк достался посту от бота: название, место, время,
 * цена, пустая строка — и только потом анонс, написанный моделью. При этом
 * анонс — это 41–53% подписи и единственное в посте, что написано словами, а
 * не собрано из полей. В уведомлении подписчик видел «🎟 Название» и хвост
 * адреса, то есть ровно ту часть, которую и так прочтёт на карточке.
 *
 * ЧТО СТАЛО. Анонс поднят под название. Название осталось первым намеренно:
 * это подпись поста в уведомлении и в пересылке, и по нему пост отличают от
 * соседнего. Тот же порядок — «сначала имя, потом слова» — уже выбран для
 * подборки недели 15.09, и расходиться рубрикам ни к чему.
 *
 * ПОЧЕМУ {lead}, А НЕ {description}. Шаблон обязан поднять наверх ТОЛЬКО
 * фразу модели. Старый ключ {description} подставляет и её, и пресс-релиз
 * источника («Приглашаем вас на ток-шоу…»), а такому тексту в первой строке
 * делать нечего. Ключи разведены в EventCaptionBuilder: {lead} пуст, когда
 * анонса модели нет (в июле таких было семь из восьми), {about} — когда он
 * есть. Пустое значение съедает свою строку целиком, и пост без анонса
 * выглядит ровно как раньше.
 *
 * СТРОКА ТЕГОВ. В шаблонах стояло `{tags|prepend:"🏷 "}`, а значения у ключа
 * `tags` нет и не было ни разу — строка не напечаталась ни в одном посте.
 * Убираем её, а не заполняем: тегер ошибается заметно (11 из 36 сеансов
 * квеста помечены «Музыкой»), и хэштеги из интересов сделали бы пост хуже.
 *
 * ОЧЕРЕДЬ ПЕРЕСОБИРАТЬ НЕ НУЖНО. Подпись собирается заново на доставке
 * (ensureEventCaption, ветка $stale) — ближайшие посты уедут уже в новом
 * порядке. Ручной текст (caption_source = manual) не трогается ничем.
 */
return new class extends Migration
{
    /** Якорь — строка названия; ниже неё и встаёт анонс. */
    private const TITLE = '🎟 <b>{title}</b>';

    public function up(): void
    {
        foreach (DB::table('telegram.message_templates')->get(['id', 'body']) as $row) {
            $body = (string) $row->body;
            $was = $body;

            // Строка тегов — из всех шаблонов: она мертва в каждом.
            $body = preg_replace('/\n*\{tags\s*\|[^{}]*\}/u', '', $body) ?? $body;

            // Анонс — только туда, где прозе уже отведено место. У шаблона
            // `short` её нет по замыслу, и подсовывать ему абзац нельзя:
            // короткий пост выбирают как раз затем, чтобы прозы не было.
            if (preg_match('/\{description\s*\|\s*slice:0\.\.(\d+)\s*\|\s*escape_html\}/u', $body, $m)) {
                $slice = $m[1];

                $body = str_replace(
                    '{description|slice:0..'.$slice.'|escape_html}',
                    '{about|slice:0..'.$slice.'|escape_html}',
                    $body,
                );

                // Идемпотентность: повторный прогон не удвоит строку.
                if (! str_contains($body, '{lead')) {
                    $body = str_replace(
                        self::TITLE."\n",
                        self::TITLE."\n".'{lead|slice:0..'.$slice.'|escape_html}'."\n",
                        $body,
                    );
                }
            }

            if ($body !== $was) {
                DB::table('telegram.message_templates')->where('id', $row->id)->update(['body' => $body]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('telegram.message_templates')->get(['id', 'body']) as $row) {
            $body = (string) $row->body;
            $was = $body;

            $body = preg_replace('/\n\{lead\s*\|[^{}]*\}/u', '', $body) ?? $body;
            $body = preg_replace(
                '/\{about(\s*\|\s*slice:0\.\.\d+\s*\|\s*escape_html)\}/u',
                '{description$1}',
                $body,
            ) ?? $body;

            // Строку тегов возвращаем туда же, где она стояла, — перед
            // подвалом со ссылками.
            if (str_contains($body, '{more_link}') && ! str_contains($body, '{tags')) {
                $body = str_replace(
                    '{more_link}',
                    '{tags|prepend:"🏷 "}'."\n\n".'{more_link}',
                    $body,
                );
            }

            if ($body !== $was) {
                DB::table('telegram.message_templates')->where('id', $row->id)->update(['body' => $body]);
            }
        }
    }
};
