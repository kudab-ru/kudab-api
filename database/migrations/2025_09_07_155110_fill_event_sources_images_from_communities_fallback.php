<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Берём первое доступное поле из списка кандидатов и ставим его как единственную картинку
        DB::unprepared(<<<'SQL'
DO $$
DECLARE
  col text;
BEGIN
  FOR col IN
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema='public'
      AND table_name='communities'
      AND column_name IN ('cover_url','avatar_url','logo_url','image_url','photo_url','image','photo')
    ORDER BY CASE column_name
               WHEN 'cover_url' THEN 1
               WHEN 'avatar_url' THEN 2
               WHEN 'logo_url' THEN 3
               WHEN 'image_url' THEN 4
               WHEN 'photo_url' THEN 5
               WHEN 'image' THEN 6
               WHEN 'photo' THEN 7
               ELSE 100
             END
  LOOP
    -- Заполняем только там, где сейчас пусто и где у сообщества поле не NULL
    EXECUTE format($f$
      UPDATE event_sources es
         SET images = json_build_array(c.%1$I),
             updated_at = NOW()
        FROM events e
        JOIN communities c ON c.id = e.community_id
       WHERE es.event_id = e.id
         AND (es.images IS NULL OR json_array_length(es.images) = 0)
         AND c.%1$I IS NOT NULL
    $f$, col);
    -- Если всё заполнено — выходим
    EXIT WHEN (SELECT COUNT(*) FROM event_sources WHERE json_array_length(images) = 0) = 0;
  END LOOP;
END
$$;
SQL);
    }

    public function down(): void
    {
        // Откат не делаем: фолбэк полезен и безвреден.
    }
};
