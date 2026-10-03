-- TES-CRIME actions (2026-10-04): guards and rulers can arrest and fine OTHER characters.
-- Live: the only arrest action was Arrest_<player>; told by the player-jarl to jail Хеймскр the
-- guard called it with target "Хеймскр" and the game arrested the player. Server: ext/tes_crime.
-- Rollback: DELETE FROM core_action WHERE code_name IN ('ArrestNPC','FineNPC');
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'ArrestNPC', 'Arrest_Person', '', '#HERIKA_NAME# arrests the person.', true, false, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "name of the character to arrest (never #PLAYER_NAME#)"}, "item": {"type": "string", "description": "days in jail, a number (default 1)"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'ArrestNPC');
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = false, available_to_narrator = false,
    description = 'Guards, commanders, housecarls, stewards, jarls only: arrest ANOTHER character (not #PLAYER_NAME#) and put them in the hold''s jail - at once, no walking. target = their name, item = days. Use it when ordered to jail someone or when you decide to.',
    updated_at = now()
WHERE code_name = 'ArrestNPC';
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'FineNPC', 'Fine_Person', '', '#HERIKA_NAME# fines the person.', true, false, false, true,
    '{"type": "object", "required": ["target", "item"], "properties": {"target": {"type": "string", "description": "name of the character to fine (never #PLAYER_NAME#)"}, "item": {"type": "string", "description": "the fine in gold, a number"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'FineNPC');
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = false, available_to_narrator = false,
    description = 'Guards, commanders, housecarls, stewards, jarls only: fine ANOTHER character (not #PLAYER_NAME#). target = their name, item = gold. They pay if they have the gold, otherwise they go to jail.',
    updated_at = now()
WHERE code_name = 'FineNPC';
-- the player's arrest stays what it was, said clearly
UPDATE public.core_action SET
    description = '#HERIKA_NAME# attempts to arrest #PLAYER_NAME# (ONLY #PLAYER_NAME#; to arrest anyone else use Arrest_Person). #PLAYER_NAME# can submit or resist. Guard-only action for serious crimes or refusal to pay.',
    updated_at = now()
WHERE code_name = 'ArrestPlayer';
