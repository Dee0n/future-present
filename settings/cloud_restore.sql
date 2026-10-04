-- TES (2026-10-04 05:00, owner: "ставь 2.5 обратно… а локальную удаляй"): everything back to what it
-- was before the local-model hour - Gemini 2.5 Flash (11) in all profile slots and global connectors,
-- fallback Gemini 2.5 Flash Lite (2), relationship system 18; the local connector is removed.
UPDATE public.core_profiles SET llm_primary_id = 11, llm_secondary_id = 11, llm_tertiary_id = 11, llm_quaternary_id = 11,
       llm_formatter_id = 11, diary_connector_id = 11, llm_fallback_id = 2 WHERE id = 1;
UPDATE public.general_settings SET value = '11', updated_at = now() WHERE id IN ('CORE_CONNECTOR_MEDIUMTERM', 'CORE_CONNECTOR_SCENECLASSIFIER',
    'CORE_CONNECTOR_PROFILES', 'CORE_CONNECTOR_DIRECTOR', 'CORE_CONNECTOR_PLAYER', 'CORE_CONNECTOR_SUMMARY', 'CORE_CONNECTOR_QUEST_ENGINE',
    'CORE_CONNECTOR_BGL', 'CORE_CONNECTOR_QUEST_CREATION');
UPDATE public.general_settings SET value = '18', updated_at = now() WHERE id = 'RELLLM_CONNECTOR';
DELETE FROM public.conf_opts WHERE id IN ('TES_AGENT_LOCAL_FIRST', 'TES_AGENT_LOCAL_ONLY');
DELETE FROM public.core_llm_connector WHERE id = 20;
