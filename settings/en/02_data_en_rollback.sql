-- 02_data_en_rollback.sql - undo settings/en/02_data_en.sql: the Russian texts come back from the
-- backup tables that script made. PREPARED, NOT APPLIED. Run by the owner only:
--     psql -U dwemer -d dwemer -v ON_ERROR_STOP=1 -f settings/en/02_data_en_rollback.sql
-- Every statement restores a text only where the live value is still the English one written by
-- 02_data_en.sql, so anything edited by hand afterwards is kept. The backup tables stay in place
-- (the script can be applied and rolled back any number of times). The rows added to tes_ui_tr
-- stay too: they are only the display cache of ext/tes_world/ui_tr.php.
--
-- Rows affected when run right after 02_data_en.sql: oghma 686 (text, then native_vector of the
-- same 686), descriptions_custom 1274, core_action 2.

\set ON_ERROR_STOP on
SET client_encoding = 'UTF8';

BEGIN;
SET LOCAL search_path TO public;
SET LOCAL lock_timeout = '10s';

DO $$
BEGIN
    IF to_regclass('public.tes_backup_oghma_ru') IS NULL
       OR to_regclass('public.tes_backup_descriptions_custom_ru') IS NULL
       OR to_regclass('public.tes_backup_core_action_ru') IS NULL THEN
        RAISE EXCEPTION 'Russian backup tables are missing - 02_data_en.sql was not applied, nothing to roll back';
    END IF;
END
$$;

-- 1. Oghma: Russian description back, field by field, only where the live field still equals the
--    English backup. updated_at returns to the saved value when the whole row is back to what it was
--    (both fields restored); a row restored only in part gets the current time.
UPDATE public.oghma o
   SET topic_desc = CASE WHEN o.topic_desc IS NOT DISTINCT FROM b.topic_desc THEN r.topic_desc ELSE o.topic_desc END,
       topic_desc_basic = CASE WHEN o.topic_desc_basic IS NOT DISTINCT FROM b.topic_desc_basic THEN r.topic_desc_basic ELSE o.topic_desc_basic END,
       updated_at = CASE WHEN o.topic_desc IS NOT DISTINCT FROM b.topic_desc
                          AND o.topic_desc_basic IS NOT DISTINCT FROM b.topic_desc_basic
                         THEN coalesce(r.updated_at, CURRENT_TIMESTAMP) ELSE CURRENT_TIMESTAMP END
  FROM public.tes_backup_oghma_ru r
  JOIN public.tes_backup_oghma_en b ON b.topic = r.topic
 WHERE r.topic = o.topic
   AND (   (o.topic_desc IS NOT DISTINCT FROM b.topic_desc AND o.topic_desc IS DISTINCT FROM r.topic_desc)
        OR (o.topic_desc_basic IS NOT DISTINCT FROM b.topic_desc_basic AND o.topic_desc_basic IS DISTINCT FROM r.topic_desc_basic));

-- native_vector: the same expression as in 02_data_en.sql (debug/db_updates.php:2128). Before
-- 02_data_en.sql the live column equalled this expression over the Russian text for all 686 rows
-- (checked 2026-10-09), so this puts back exactly the value that was there.
UPDATE public.oghma
   SET native_vector = setweight(to_tsvector('english', coalesce(topic, '')), 'A')
                    || setweight(to_tsvector('english', coalesce(topic_desc, '')), 'B')
 WHERE topic IN (SELECT topic FROM public.tes_backup_oghma_ru)
   AND native_vector IS DISTINCT FROM (setweight(to_tsvector('english', coalesce(topic, '')), 'A')
                                    || setweight(to_tsvector('english', coalesce(topic_desc, '')), 'B'));

-- 2. Descriptions: Russian description back, only where the live description is still the built-in
--    English one. The name is NOT written: 02_data_en.sql never changed it, so a name edited by hand
--    after that script stays as the owner left it.
UPDATE public.descriptions_custom c
   SET description = r.description
  FROM public.tes_backup_descriptions_custom_ru r
  JOIN public.descriptions d ON d.plugin = r.plugin AND d.baseid = r.baseid
 WHERE r.plugin = c.plugin
   AND r.baseid = c.baseid
   AND c.description IS NOT DISTINCT FROM d.description
   AND c.description IS DISTINCT FROM r.description;

-- 3. Actions: the two Russian texts back, only where the live text is still the English backup.
--    action_name and code_name are compared, never written. updated_at returns to the saved value
--    (lib/core/action_catalog.php only ever writes this column; no read of it was found there or in
--    ui/function_editor.php, functions/functions.php, processor/import_files.php).
UPDATE public.core_action a
   SET description = r.description,
       return_message = r.return_message,
       updated_at = coalesce(r.updated_at, now())
  FROM public.tes_backup_core_action_ru r
  JOIN public.tes_backup_core_action_en b ON b.code_name = r.code_name
 WHERE r.code_name = a.code_name
   AND r.action_name = a.action_name
   AND a.code_name IN ('GodCommand', 'WriteDocument')
   AND a.description IS NOT DISTINCT FROM b.description
   AND (a.description IS DISTINCT FROM r.description OR a.return_message IS DISTINCT FROM r.return_message);

SELECT 'oghma: Russian topic_desc now' AS what, count(*) AS n FROM public.oghma WHERE topic_desc ~ '[А-Яа-яЁё]'
UNION ALL SELECT 'oghma: Russian topic_desc_basic now', count(*) FROM public.oghma WHERE coalesce(topic_desc_basic, '') ~ '[А-Яа-яЁё]'
UNION ALL SELECT 'descriptions_custom: Russian descriptions now', count(*) FROM public.descriptions_custom WHERE description ~ '[А-Яа-яЁё]'
UNION ALL SELECT 'core_action: descriptions mostly Cyrillic now', count(*) FROM public.core_action
           WHERE length(regexp_replace(description, '[^А-Яа-яЁё]', '', 'g')) > length(regexp_replace(description, '[^A-Za-z]', '', 'g'));

COMMIT;

-- Other ways back, not part of this script:
--   * all descriptions to the built-in English set with English names: Description Manager ->
--     "delete all custom" (TRUNCATE descriptions_custom) - the Russian names are lost with it and the
--     lookups by name stop matching a Russian game;
--   * one Oghma article to the factory text: Oghma manager -> reset (lib/oghma_catalog.php
--     restoreFactoryProjection) - factory rows only, aliases return to the catalog's own.
