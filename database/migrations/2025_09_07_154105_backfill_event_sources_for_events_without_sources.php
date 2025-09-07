<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::statement("
                INSERT INTO event_sources (
                    event_id, social_link_id, context_post_id, source, post_external_id,
                    external_url, published_at, images, meta, generated_link,
                    created_at, updated_at
                )
                SELECT
                    e.id,
                    csl.id,
                    cp.id,
                    COALESCE(LOWER(sn.slug), LOWER(cp.source), 'site') AS source,
                    COALESCE(cp.external_id::text, cp.id::text)       AS post_external_id,
                    NULL::text                                         AS external_url,
                    cp.published_at,
                    '[]'::json                                         AS images,
                    '{}'::json                                         AS meta,
                    NULL::text                                         AS generated_link,
                    NOW(), NOW()
                FROM events e
                JOIN context_posts cp ON cp.id = e.original_post_id
                LEFT JOIN community_social_links csl ON csl.id = cp.social_link_id
                LEFT JOIN social_networks sn ON sn.id = csl.social_network_id
                WHERE e.deleted_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM event_sources es0 WHERE es0.event_id = e.id)
                  AND COALESCE(cp.external_id::text, cp.id::text) IS NOT NULL
                ON CONFLICT ON CONSTRAINT uq_event_sources_source_post_event DO NOTHING
            ");
        });
    }

    public function down(): void
    {
        // Откат не делаем (данные полезные).
    }
};
