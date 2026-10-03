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
