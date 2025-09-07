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
            DB::statement("
                WITH src AS (
                  SELECT
                    es2.id,
                    COALESCE(
                      (
                        SELECT json_agg(u) FROM (
                          SELECT DISTINCT COALESCE(a.url, a.preview_url) AS u
                          FROM attachments a
                          WHERE a.parent_type = 'App\\Models\\ContextPost'
                            AND a.parent_id IN (
                              es2.context_post_id,
                              (SELECT original_post_id FROM events WHERE id = es2.event_id)
                            )
                            AND a.type IN ('image','photo')
                            AND COALESCE(a.url, a.preview_url) IS NOT NULL
                          ORDER BY u
                          LIMIT 6
                        ) s
                      ),
                      '[]'::json
                    ) AS images
                  FROM event_sources es2
                )
                UPDATE event_sources es
                   SET images = src.images,
                       updated_at = NOW()
                  FROM src
                 WHERE es.id = src.id
                   AND (es.images IS NULL OR json_array_length(es.images) = 0)
            ");
        });
    }

    public function down(): void
    {
        // Откат не обязателен.
    }
};
