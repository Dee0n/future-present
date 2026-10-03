-- TES (2026-10-04): local model as a CHIM connector - Qwen3.5 4B (Q4_K_M, text only) in LM Studio on
-- the Windows host, ~3.4 GB VRAM at 14k context. docs/local-llm.md. The URL holds the WSL gateway
-- address of the host; if it changes after a reboot: bash tools/local_llm.sh
-- Rollback: DELETE FROM core_llm_connector WHERE id = 20;
INSERT INTO public.core_llm_connector (id, label, metadata, url, model, provider, driver, max_tokens, enforce_json, prefill_json, api_badge_id, json_schema, temperature, service)
SELECT 20, 'Local Qwen3.5 4B (LM Studio)', '{"lmstudio_compat": true, "extra_parameters": {"reasoning_effort": "none"}, "extra_parameters_enabled": true}'::jsonb, 'http://172.22.208.1:1234/v1/chat/completions', 'qwen/qwen3.5-4b', 'lmstudio', 'openaijson', 600, 1, 0, 2, 1, 0.7, 'openai'
WHERE NOT EXISTS (SELECT 1 FROM public.core_llm_connector WHERE id = 20);
-- reasoning_effort none: with thinking on, the model spends the whole max_tokens on it and the line is empty
UPDATE public.core_llm_connector SET metadata = '{"lmstudio_compat": true, "extra_parameters": {"reasoning_effort": "none"}, "extra_parameters_enabled": true}'::jsonb WHERE id = 20;
