<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Возврат жирного названия вместо курсивного.
//
// Курсив был скопирован у московского агрегатора вместе с формой «цитата», но
// там он уместен: у них весь пост — портрет места, и курсивная строка работает
// подзаголовком. У нас пост — афиша, где после названия идут строки места,
// времени и цены; жирное название держит иерархию и видно в ленте с первого
// взгляда, курсивное с ними сливается.
//
// Значок по теме ({kind_emoji}) и сама форма «цитата» остаются — к ним вопросов
// не было.
return new class extends Migration
{
    public function up(): void
    {
        self::swap('<i>{title}</i>', '<b>{title}</b>');
    }

    public function down(): void
    {
        self::swap('<b>{title}</b>', '<i>{title}</i>');
    }

    private static function swap(string $from, string $to): void
    {
        foreach (DB::table('telegram.message_templates')->get(['id', 'body']) as $row) {
            $body = (string) $row->body;
            if (! str_contains($body, $from)) {
                continue;
            }

            DB::table('telegram.message_templates')->where('id', $row->id)->update([
                'body' => str_replace($from, $to, $body),
                'updated_at' => now(),
            ]);
        }
    }
};
