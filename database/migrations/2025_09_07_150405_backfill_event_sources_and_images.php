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
            // 3.1. Создаём отсутствующие event_sources по связкам events → context_posts
            // Берём source: social_networks.slug || context_posts.source || 'site'
            DB::statement("
                INSERT INTO event_sources (
                    event_id, social_link_id, context_post_id, source, post_external_id,
                    external_url, published_at, images, meta, generated_link,
                    created_at, updated_at
                )
                SELECT
                    e.id AS event_id,
                    csl.id AS social_link_id,
                    cp.id AS context_post_id,
                    COALESCE(LOWER(sn.slug), LOWER(cp.source), 'site') AS source,
                    COALESCE(cp.external_id::text, cp.id::text) AS post_external_id,
                    NULL::text AS external_url,
                    cp.published_at,
                    '[]'::json AS images,
                    '{}'::json AS meta,
                    NULL::text AS generated_link,
                    NOW(), NOW()
                FROM events e
                JOIN context_posts cp ON cp.id = e.original_post_id
                LEFT JOIN community_social_links csl ON csl.id = cp.social_link_id
                LEFT JOIN social_networks sn ON sn.id = csl.social_network_id
                WHERE e.deleted_at IS NULL
                  AND COALESCE(cp.external_id::text, cp.id::text) IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM event_sources es
                      WHERE es.source = COALESCE(LOWER(sn.slug), LOWER(cp.source), 'site')
                        AND es.post_external_id = COALESCE(cp.external_id::text, cp.id::text)
                  )
            ");

            // 3.2. Заполняем images там, где пусто — берём до 6 ссылок из attachments поста
            DB::statement("
                UPDATE event_sources es
                SET images = sub.images
                FROM (
                    SELECT
                        es2.id,
                        COALESCE(
                          (
                            SELECT json_agg(s.url)
                            FROM (
                              SELECT a.url
                              FROM attachments a
                              WHERE a.parent_type = 'App\\Models\\ContextPost'
                                AND a.parent_id = es2.context_post_id
                                AND a.type IN ('image','photo')
                              ORDER BY a.\"order\" NULLS FIRST, a.id
                              LIMIT 6
                            ) AS s
                          ),
                          '[]'::json
                        ) AS images
                    FROM event_sources es2
                ) AS sub
                WHERE es.id = sub.id
                  AND (es.images IS NULL OR json_array_length(es.images) = 0)
            ");
        });
    }

    public function down(): void
    {
        // Деструктивный откат не делаем: данные уже созданы/обогащены.
        // При необходимости можно удалить записи без связей:
        // DB::statement(\"DELETE FROM event_sources WHERE created_at = updated_at AND images = '[]'::json\");
    }
};
