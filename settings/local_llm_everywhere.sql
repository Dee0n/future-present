-- TES (2026-10-04, owner: "везде ставь вместо облачной локальную"): every LLM slot of the profile
-- and the goal agent use the local model (connector 20, settings/local_llm.sql).
-- Was: primary/secondary/tertiary/quaternary/formatter/diary = 11 (Gemini 2.5 Flash), fallback = 2.
-- Back to the cloud:
--   UPDATE core_profiles SET llm_primary_id=11, llm_secondary_id=11, llm_tertiary_id=11, llm_quaternary_id=11,
--          llm_formatter_id=11, diary_connector_id=11, llm_fallback_id=2 WHERE id=1;
--   DELETE FROM conf_opts WHERE id='TES_AGENT_LOCAL_FIRST';
UPDATE public.core_profiles SET llm_primary_id = 20, llm_secondary_id = 20, llm_tertiary_id = 20, llm_quaternary_id = 20,
       llm_formatter_id = 20, diary_connector_id = 20, llm_fallback_id = 20 WHERE id = 1;
INSERT INTO public.conf_opts (id, value) SELECT 'TES_AGENT_LOCAL_FIRST', '1' WHERE NOT EXISTS (SELECT 1 FROM public.conf_opts WHERE id = 'TES_AGENT_LOCAL_FIRST');
UPDATE public.conf_opts SET value = '1' WHERE id = 'TES_AGENT_LOCAL_FIRST';
-- the profile carries its own RECHAT_P and it wins over conf_opts (settings/rechat.sql set only that)
UPDATE public.core_profiles SET metadata = jsonb_set(metadata, '{RECHAT_P}', '25'::jsonb) WHERE id = 1 AND metadata ? 'RECHAT_P';

-- Second pass (same day, owner again: "везде ставь локалку"): the GLOBAL connectors live in
-- general_settings, not in the profile - summaries, memory, scene classifier, dynamic profiles,
-- director, player respeech, quests, background life were still on 11 (Gemini 2.5 Flash) and the
-- relationship system on 18. Back to the cloud:
--   UPDATE general_settings SET value='11' WHERE id IN ('CORE_CONNECTOR_MEDIUMTERM','CORE_CONNECTOR_SCENECLASSIFIER','CORE_CONNECTOR_PROFILES',
--     'CORE_CONNECTOR_DIRECTOR','CORE_CONNECTOR_PLAYER','CORE_CONNECTOR_SUMMARY','CORE_CONNECTOR_QUEST_ENGINE','CORE_CONNECTOR_BGL','CORE_CONNECTOR_QUEST_CREATION');
--   UPDATE general_settings SET value='18' WHERE id='RELLLM_CONNECTOR';
--   DELETE FROM conf_opts WHERE id='TES_AGENT_LOCAL_ONLY';
UPDATE public.general_settings SET value = '20', updated_at = now() WHERE id IN ('CORE_CONNECTOR_MEDIUMTERM', 'CORE_CONNECTOR_SCENECLASSIFIER',
    'CORE_CONNECTOR_PROFILES', 'CORE_CONNECTOR_DIRECTOR', 'CORE_CONNECTOR_PLAYER', 'CORE_CONNECTOR_SUMMARY', 'CORE_CONNECTOR_QUEST_ENGINE',
    'CORE_CONNECTOR_BGL', 'CORE_CONNECTOR_QUEST_CREATION', 'RELLLM_CONNECTOR');
-- the goal agent never falls back to the cloud
INSERT INTO public.conf_opts (id, value) SELECT 'TES_AGENT_LOCAL_ONLY', '1' WHERE NOT EXISTS (SELECT 1 FROM public.conf_opts WHERE id = 'TES_AGENT_LOCAL_ONLY');
