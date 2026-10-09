-- 02_data_en.sql - DATA texts that reach the model go back to English.
-- PREPARED, NOT APPLIED. Run by the owner only:
--     psql -U dwemer -d dwemer -v ON_ERROR_STOP=1 -f settings/en/02_data_en.sql
-- Dry run: replace the final COMMIT with ROLLBACK - the counts are printed either way.
-- Rollback: settings/en/02_data_en_rollback.sql. Details and measurements: settings/en/README_data.md
--
-- What changes (live state measured 2026-10-09, schema public of database dwemer):
--   oghma                686 rows  topic_desc (686) / topic_desc_basic (572) Russian -> English
--                                  from tes_backup_oghma_en (topics '16_accords_of_madness' .. 'gutworm';
--                                  the other 921 rows were never translated and are not touched)
--   oghma                686 rows  native_vector of the same rows rebuilt (expression of
--                                  debug/db_updates.php:2128 - the one the live column holds for all
--                                  1607 rows; the column is not read by any code)
--   descriptions_custom 1274 rows  description Russian -> English of the built-in table "descriptions";
--                                  the Russian NAME stays (it is the lookup key for a Russian game)
--   core_action            2 rows  GodCommand, WriteDocument: description + return_message -> English
--                                  from tes_backup_core_action_en. Action NAMES are not touched.
--   tes_ui_tr          <=2464 rows added: the Russian translations we already paid for become the
--                                  display cache of ext/tes_world/ui_tr.php (owner reads Russian,
--                                  the model gets English)
-- What does NOT change: oghma.topic / aliases / retrieval_phrases / tags (the Russian speech search
-- runs on topic + aliases, never on the description), core_npc_master, memory, memory_summary,
-- diarylog, prompts, any playthrough snapshot schema (chim_profile_*).

\set ON_ERROR_STOP on
SET client_encoding = 'UTF8';

BEGIN;
SET LOCAL search_path TO public;
SET LOCAL lock_timeout = '10s';

-- ---------------------------------------------------------------------------------------------
-- 0. Preconditions: stop before touching anything if the English sources are not what was measured
-- ---------------------------------------------------------------------------------------------
DO $$
DECLARE
    n integer;
BEGIN
    IF to_regclass('public.tes_backup_oghma_en') IS NULL OR to_regclass('public.tes_backup_core_action_en') IS NULL THEN
        RAISE EXCEPTION 'English backup tables are missing - nothing to restore from';
    END IF;

    SELECT count(*) INTO n FROM (SELECT topic FROM public.tes_backup_oghma_en GROUP BY topic HAVING count(*) > 1) d;
    IF n > 0 THEN
        RAISE EXCEPTION 'tes_backup_oghma_en has % duplicated topic(s) - restore would be ambiguous', n;
    END IF;

    SELECT count(*) INTO n FROM public.tes_backup_oghma_en
     WHERE topic_desc ~ '[А-Яа-яЁё]' OR coalesce(topic_desc_basic, '') ~ '[А-Яа-яЁё]';
    IF n > 0 THEN
        RAISE EXCEPTION 'tes_backup_oghma_en holds % Russian row(s) - it is not an English backup', n;
    END IF;

    SELECT count(*) INTO n FROM public.oghma o LEFT JOIN public.tes_backup_oghma_en b ON b.topic = o.topic
     WHERE (o.topic_desc ~ '[А-Яа-яЁё]' OR coalesce(o.topic_desc_basic, '') ~ '[А-Яа-яЁё]') AND b.topic IS NULL;
    IF n > 0 THEN
        RAISE NOTICE '% Russian Oghma article(s) have no English backup and stay Russian (written after 2026-10-04?)', n;
    END IF;

    -- the two action texts are restored only if the backup still holds exactly the texts checked on
    -- 2026-10-09 (GodCommand = settings/chim_settings.sql:69-99, WriteDocument = settings/prompt_trim.sql:13)
    SELECT count(*) INTO n FROM public.tes_backup_core_action_en
     WHERE (code_name = 'GodCommand'    AND md5(description) = 'd74d89f99b6ff7d000b150e4442fdc59' AND return_message = 'Done: #TARGET#')
        OR (code_name = 'WriteDocument' AND md5(description) = '61f056e96ffdcdd8982548a2c4da4755'
            AND return_message = '#HERIKA_NAME# hands #PLAYER_NAME# a written paper.');
    IF n <> 2 THEN
        RAISE EXCEPTION 'tes_backup_core_action_en does not hold the expected English GodCommand / WriteDocument texts (% of 2)', n;
    END IF;
END
$$;

-- ---------------------------------------------------------------------------------------------
-- 1. Backups of what is about to change (kept forever; tables outside the playthrough policy
--    lists of lib/playthrough_policy.php are never cleared by the core)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.tes_backup_oghma_ru (
    topic            character varying PRIMARY KEY,
    topic_desc       character varying,
    topic_desc_basic text,
    updated_at       timestamptz,
    saved_at         timestamptz NOT NULL DEFAULT now()
);
-- ON CONFLICT DO NOTHING: a second run never replaces the Russian text saved by the first one
INSERT INTO public.tes_backup_oghma_ru (topic, topic_desc, topic_desc_basic, updated_at)
SELECT topic, topic_desc, topic_desc_basic, updated_at
  FROM public.oghma
 WHERE topic_desc ~ '[А-Яа-яЁё]' OR coalesce(topic_desc_basic, '') ~ '[А-Яа-яЁё]'
ON CONFLICT (topic) DO NOTHING;

CREATE TABLE IF NOT EXISTS public.tes_backup_descriptions_custom_ru (
    plugin      text NOT NULL,
    baseid      character varying(128) NOT NULL,
    name        text,
    description text,
    saved_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (plugin, baseid)
);
INSERT INTO public.tes_backup_descriptions_custom_ru (plugin, baseid, name, description)
SELECT plugin, baseid, name, description FROM public.descriptions_custom
ON CONFLICT (plugin, baseid) DO NOTHING;

CREATE TABLE IF NOT EXISTS public.tes_backup_core_action_ru (
    code_name      character varying(128) PRIMARY KEY,
    action_name    character varying(255),
    description    text,
    return_message text,
    updated_at     timestamp,
    saved_at       timestamptz NOT NULL DEFAULT now()
);
INSERT INTO public.tes_backup_core_action_ru (code_name, action_name, description, return_message, updated_at)
SELECT code_name, action_name, description, return_message, updated_at FROM public.core_action
ON CONFLICT (code_name) DO NOTHING;

-- ---------------------------------------------------------------------------------------------
-- 2. Display cache: English text -> the Russian translation already stored, for the owner's pages
--    (ext/tes_world/ui_ru.js sends the English text it sees, ui_tr.php answers from tes_ui_tr).
--    Must run BEFORE the updates below - it reads the live Russian texts. ui_tr.php ignores
--    strings longer than 2000 characters (ui_tr.php:18), so those are not seeded.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.tes_ui_tr (
    src        text PRIMARY KEY,
    ru         text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO public.tes_ui_tr (src, ru)
SELECT DISTINCT ON (src) src, ru
  FROM (
        SELECT regexp_replace(replace(b.topic_desc, E'\r\n', E'\n'), '^\s+|\s+$', '', 'g') AS src,
               regexp_replace(replace(o.topic_desc, E'\r\n', E'\n'), '^\s+|\s+$', '', 'g') AS ru
          FROM public.oghma o JOIN public.tes_backup_oghma_en b ON b.topic = o.topic
         WHERE o.topic_desc ~ '[А-Яа-яЁё]' AND b.topic_desc !~ '[А-Яа-яЁё]'
        UNION ALL
        SELECT regexp_replace(replace(b.topic_desc_basic, E'\r\n', E'\n'), '^\s+|\s+$', '', 'g'),
               regexp_replace(replace(o.topic_desc_basic, E'\r\n', E'\n'), '^\s+|\s+$', '', 'g')
          FROM public.oghma o JOIN public.tes_backup_oghma_en b ON b.topic = o.topic
         WHERE coalesce(o.topic_desc_basic, '') ~ '[А-Яа-яЁё]' AND coalesce(b.topic_desc_basic, '') !~ '[А-Яа-яЁё]'
        UNION ALL
        SELECT regexp_replace(replace(d.description, E'\r\n', E'\n'), '^\s+|\s+$', '', 'g'),
               regexp_replace(replace(c.description, E'\r\n', E'\n'), '^\s+|\s+$', '', 'g')
          FROM public.descriptions_custom c
          JOIN public.descriptions d ON d.plugin = c.plugin AND d.baseid = c.baseid
         WHERE c.description ~ '[А-Яа-яЁё]' AND d.description !~ '[А-Яа-яЁё]'
       ) s
 WHERE length(src) BETWEEN 2 AND 2000 AND ru <> ''
 ORDER BY src, ru
ON CONFLICT (src) DO NOTHING;

-- ---------------------------------------------------------------------------------------------
-- 3. Oghma: Russian description -> English from the backup. Only fields that are Russian now and
--    English in the backup; an article edited in English after the backup is left alone.
--    topic, aliases, retrieval_phrases, tags, knowledge classes, source_* are not touched.
-- ---------------------------------------------------------------------------------------------
UPDATE public.oghma o
   SET topic_desc = CASE WHEN o.topic_desc ~ '[А-Яа-яЁё]' AND b.topic_desc !~ '[А-Яа-яЁё]' AND btrim(b.topic_desc) <> ''
                         THEN b.topic_desc ELSE o.topic_desc END,
       topic_desc_basic = CASE WHEN coalesce(o.topic_desc_basic, '') ~ '[А-Яа-яЁё]' AND coalesce(b.topic_desc_basic, '') !~ '[А-Яа-яЁё]'
                               THEN b.topic_desc_basic ELSE o.topic_desc_basic END,
       updated_at = CURRENT_TIMESTAMP
  FROM public.tes_backup_oghma_en b
 WHERE b.topic = o.topic
   AND (   (o.topic_desc ~ '[А-Яа-яЁё]' AND b.topic_desc !~ '[А-Яа-яЁё]' AND btrim(b.topic_desc) <> '')
        OR (coalesce(o.topic_desc_basic, '') ~ '[А-Яа-яЁё]' AND coalesce(b.topic_desc_basic, '') !~ '[А-Яа-яЁё]'));

-- full-text column of the restored rows. Housekeeping only - no code reads native_vector (the search
-- runs on the topic/alias lexicon, lib/oghma_retrieval.php:136-141). The expression is the one the live
-- column holds today for all 1607 rows (checked 2026-10-09 with a SELECT): debug/db_updates.php:2128,
-- which the core runs unconditionally on every database-update pass (text search config "english" is
-- the database default, pinned here so a session setting cannot change the result). It is deliberately
-- NOT chimOghmaNativeVectorSql() (lib/oghma_aliases.php:171-174): that form matches only 183 of the
-- 1607 live rows (76 of these 686), would leave these rows different from the rest, and the rollback
-- could not reproduce the original value. tools/apply_oghma_aliases.py does not write this column, so the alias step changes nothing here.
UPDATE public.oghma
   SET native_vector = setweight(to_tsvector('english', coalesce(topic, '')), 'A')
                    || setweight(to_tsvector('english', coalesce(topic_desc, '')), 'B')
 WHERE topic IN (SELECT topic FROM public.tes_backup_oghma_ru)
   AND native_vector IS DISTINCT FROM (setweight(to_tsvector('english', coalesce(topic, '')), 'A')
                                    || setweight(to_tsvector('english', coalesce(topic_desc, '')), 'B'));

-- ---------------------------------------------------------------------------------------------
-- 4. Item / spell / faction descriptions: English text of the built-in row, Russian name kept.
--    The core finds a description by FormID first and by exact name second
--    (lib/data_functions.php:304-326, :366-372, :7544; functions/functions.php:316-322) - the game
--    is Russian, so the Russian name must stay or the name lookups stop matching.
-- ---------------------------------------------------------------------------------------------
UPDATE public.descriptions_custom c
   SET description = d.description
  FROM public.descriptions d
 WHERE d.plugin = c.plugin
   AND d.baseid = c.baseid
   AND c.description ~ '[А-Яа-яЁё]'
   AND coalesce(btrim(d.description), '') <> ''
   AND d.description !~ '[А-Яа-яЁё]';

-- ---------------------------------------------------------------------------------------------
-- 5. Actions: the two descriptions the 2026-10-04 15:10 revert left Russian. English texts:
--    GodCommand.description    = settings/chim_settings.sql:69-99 (5814 chars, md5 d74d89f9...),
--                                "God mode: run Skyrim console commands to change the world directly. ..."
--    GodCommand.return_message = 'Done: #TARGET#'
--    WriteDocument.description = '#HERIKA_NAME# writes a real paper for #PLAYER_NAME# (receipt, contract,
--        deed, permit, letter, note). target = "<paper name>: <text in Russian, signed>", e.g. Расписка:
--        Получил от Шамана 500 септимов. Назим. Use it whenever #HERIKA_NAME# promises to write or sign
--        something - do not just talk about it.'
--    WriteDocument.return_message = '#HERIKA_NAME# hands #PLAYER_NAME# a written paper.'
--    The Russian words left inside are data the model must output or the parser reads
--    ({npc:Амрен}, find предмет|заклинание|..., the example document), not instructions.
--    action_name and code_name are compared, never written.
-- ---------------------------------------------------------------------------------------------
UPDATE public.core_action a
   SET description = b.description,
       return_message = b.return_message,
       updated_at = now()
  FROM public.tes_backup_core_action_en b
 WHERE b.code_name = a.code_name
   AND b.action_name = a.action_name
   AND a.code_name IN ('GodCommand', 'WriteDocument')
   AND (a.description IS DISTINCT FROM b.description OR a.return_message IS DISTINCT FROM b.return_message);

-- ---------------------------------------------------------------------------------------------
-- 6. Result (expected: 0 / 0 / 0 Russian texts left; aliases line shows whether step "aliases" of
--    README_data.md was done)
-- ---------------------------------------------------------------------------------------------
SELECT 'oghma: Russian topic_desc left' AS what, count(*) AS n FROM public.oghma WHERE topic_desc ~ '[А-Яа-яЁё]'
UNION ALL SELECT 'oghma: Russian topic_desc_basic left', count(*) FROM public.oghma WHERE coalesce(topic_desc_basic, '') ~ '[А-Яа-яЁё]'
UNION ALL SELECT 'oghma: rows differing from the English backup', count(*) FROM public.oghma o JOIN public.tes_backup_oghma_en b ON b.topic = o.topic
           WHERE o.topic_desc IS DISTINCT FROM b.topic_desc OR o.topic_desc_basic IS DISTINCT FROM b.topic_desc_basic
UNION ALL SELECT 'oghma: topics with Russian aliases (0 = Russian speech finds nothing)', count(*) FROM public.oghma WHERE aliases ~ '[А-Яа-яЁё]'
UNION ALL SELECT 'descriptions_custom: Russian descriptions left', count(*) FROM public.descriptions_custom WHERE description ~ '[А-Яа-яЁё]'
UNION ALL SELECT 'descriptions_custom: Russian names kept', count(*) FROM public.descriptions_custom WHERE name ~ '[А-Яа-яЁё]'
UNION ALL SELECT 'core_action: descriptions mostly Cyrillic', count(*) FROM public.core_action
           WHERE length(regexp_replace(description, '[^А-Яа-яЁё]', '', 'g')) > length(regexp_replace(description, '[^A-Za-z]', '', 'g'))
UNION ALL SELECT 'backup rows: tes_backup_oghma_ru', count(*) FROM public.tes_backup_oghma_ru
UNION ALL SELECT 'backup rows: tes_backup_descriptions_custom_ru', count(*) FROM public.tes_backup_descriptions_custom_ru
UNION ALL SELECT 'backup rows: tes_backup_core_action_ru', count(*) FROM public.tes_backup_core_action_ru
UNION ALL SELECT 'display cache rows: tes_ui_tr', count(*) FROM public.tes_ui_tr;

COMMIT;

-- ---------------------------------------------------------------------------------------------
-- NOT PART OF THIS SCRIPT (owner decides, see README_data.md "Память и дневники"):
-- memory summaries are never shown to the player. Their language is set by prompts.summary_prompt,
-- which belongs to settings/en/01_prompts_en.sql (line 333): that script keeps the summaries Russian
-- ("Write the summary in Russian (Cyrillic). Keep names exactly as written in the context.").
-- English summaries are a one-line change there, in the same statement:
--     "... Write the summary and the tags in English. Keep names exactly as written in the context."
-- Nothing is written to public.prompts from this file, so the two scripts never touch the same row.
-- Diaries are not affected either way: their language is set by
-- core_profiles.metadata->>'DIARY_PROMPT' (profile 1) and prompts.player_diary_prompt.
