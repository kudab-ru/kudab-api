<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // not_before -> timestamptz
        DB::unprepared(<<<'SQL'
DO $$
DECLARE typ text;
BEGIN
  SELECT c.data_type INTO typ
  FROM information_schema.columns c
  WHERE c.table_name='tg_broadcast_rules' AND c.column_name='not_before';

  IF typ = 'timestamp with time zone' THEN
    -- уже ок, ничего не делаем
    RAISE NOTICE 'not_before already timestamptz';

  ELSIF typ = 'timestamp without time zone' THEN
    EXECUTE $m$
      ALTER TABLE tg_broadcast_rules
      ALTER COLUMN not_before TYPE timestamptz
      USING (not_before AT TIME ZONE current_setting('TIMEZONE'))
    $m$;

  ELSIF typ = 'time without time zone' THEN
    -- трактуем как "сегодня HH:MM" в TZ БД
    EXECUTE $m$
      ALTER TABLE tg_broadcast_rules
      ALTER COLUMN not_before TYPE timestamptz
      USING (
        ((date_trunc('day', now())::date + not_before)::timestamp)
        AT TIME ZONE current_setting('TIMEZONE')
      )
    $m$;

  ELSIF typ = 'time with time zone' THEN
    -- отбрасываем TZ у timetz, затем как выше
    EXECUTE $m$
      ALTER TABLE tg_broadcast_rules
      ALTER COLUMN not_before TYPE timestamptz
      USING (
        ((date_trunc('day', now())::date + (not_before::time))::timestamp)
        AT TIME ZONE current_setting('TIMEZONE')
      )
    $m$;

  ELSE
    -- попытка обобщённого приведения (на всякий случай)
    EXECUTE $m$
      ALTER TABLE tg_broadcast_rules
      ALTER COLUMN not_before TYPE timestamptz
      USING (
        CASE
          WHEN not_before IS NULL THEN NULL::timestamptz
          ELSE (
            ((date_trunc('day', now())::date + (not_before::time))::timestamp)
            AT TIME ZONE current_setting('TIMEZONE')
          )
        END
      )
    $m$;
  END IF;
END $$;
SQL);

        // not_after -> timestamptz
        DB::unprepared(<<<'SQL'
DO $$
DECLARE typ text;
BEGIN
  SELECT c.data_type INTO typ
  FROM information_schema.columns c
  WHERE c.table_name='tg_broadcast_rules' AND c.column_name='not_after';

  IF typ = 'timestamp with time zone' THEN
    RAISE NOTICE 'not_after already timestamptz';

  ELSIF typ = 'timestamp without time zone' THEN
    EXECUTE $m$
      ALTER TABLE tg_broadcast_rules
      ALTER COLUMN not_after TYPE timestamptz
      USING (not_after AT TIME ZONE current_setting('TIMEZONE'))
    $m$;

  ELSIF typ = 'time without time zone' THEN
    EXECUTE $m$
      ALTER TABLE tg_broadcast_rules
      ALTER COLUMN not_after TYPE timestamptz
      USING (
        ((date_trunc('day', now())::date + not_after)::timestamp)
        AT TIME ZONE current_setting('TIMEZONE')
      )
    $m$;

  ELSIF typ = 'time with time zone' THEN
    EXECUTE $m$
      ALTER TABLE tg_broadcast_rules
      ALTER COLUMN not_after TYPE timestamptz
      USING (
        ((date_trunc('day', now())::date + (not_after::time))::timestamp)
        AT TIME ZONE current_setting('TIMEZONE')
      )
    $m$;

  ELSE
    EXECUTE $m$
      ALTER TABLE tg_broadcast_rules
      ALTER COLUMN not_after TYPE timestamptz
      USING (
        CASE
          WHEN not_after IS NULL THEN NULL::timestamptz
          ELSE (
            ((date_trunc('day', now())::date + (not_after::time))::timestamp)
            AT TIME ZONE current_setting('TIMEZONE')
          )
        END
      )
    $m$;
  END IF;
END $$;
SQL);
    }

    public function down(): void
    {
        // Откат в time небезопасен — оставляем timestamptz
    }
};
