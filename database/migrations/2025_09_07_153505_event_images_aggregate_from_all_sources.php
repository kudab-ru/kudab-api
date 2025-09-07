<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // imgs: соберём по каждому событию до 6 уникальных ссылок (url/preview_url)
            DB::statement("
                WITH imgs AS (
                  SELECT i.event_id,
                         json_agg(i.u ORDER BY i.pub DESC, i.ord_nulls, i.aid) AS images
                  FROM (
                    SELECT DISTINCT ON (es.event_id, COALESCE(a.url, a.preview_url))
                           es.event_id,
                           COALESCE(a.url, a.preview_url) AS u,
                           es.published_at AS pub,
                           (a.\"order\" IS NULL) AS ord_nulls,
                           a.id AS aid
                    FROM event_sources es
                    JOIN attachments a
                      ON a.parent_type = 'App\\Models\\ContextPost'
                     AND a.parent_id  = es.context_post_id
                    WHERE a.type IN ('image','photo')
                      AND COALESCE(a.url, a.preview_url) IS NOT NULL
                    ORDER BY es.event_id, COALESCE(a.url, a.preview_url), es.published_at DESC, a.\"order\" NULLS FIRST, a.id
                  ) i
                  GROUP BY i.event_id
                ),
                latest AS (
                  SELECT DISTINCT ON (event_id) id, event_id
                  FROM event_sources
                  ORDER BY event_id, published_at DESC, id DESC
                ),
                sub AS (
                  SELECT l.id, im.images
                  FROM latest l
                  JOIN imgs im ON im.event_id = l.event_id
                )
                UPDATE event_sources es
                   SET images = sub.images,
                       updated_at = NOW()
                  FROM sub
                 WHERE es.id = sub.id
                   AND (es.images IS NULL OR json_array_length(es.images) = 0)
            ");
        });
    }

    public function down(): void
    {
        // Идемпотентно: откат не обязателен.
    }
};
