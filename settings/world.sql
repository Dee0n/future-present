-- TES-WORLD (2026-10-04): an NPC ordered about by the player-ruler can have the order really done.
-- Server: ext/tes_world/functions.php -> ext/tes_agent (goal agent). Works only while the player
-- holds a title (player.title …). Rollback: DELETE FROM core_action WHERE code_name='CarryOutOrder';
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'CarryOutOrder', 'Carry_Out_Order', '', '#HERIKA_NAME# sees to it that the order is carried out.', true, true, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "the order in plain words, with names: who, what, to whom"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'CarryOutOrder');
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = true, available_to_narrator = false,
    description = 'When #PLAYER_NAME#, your ruler, orders something you cannot do with your other actions - do something to another person or to yourself (undress or dress, take or give things, bring someone, heal, punish), appoint or dismiss, give or take a post, property or money, free a prisoner - use this instead of promising: target = the order in plain words with names. It WILL really be done. For arrests and fines use Arrest_Person / Fine_Person.',
    updated_at = now()
WHERE code_name = 'CarryOutOrder';
