-- PROMPT-TRIM (2026-10-04, owner: "сокращать промпты не будешь?"). An NPC prompt was ~42k chars
-- (~14k tokens); the action list alone 9.3k for 33 actions, and the model skipped instructions.
-- 1) shorter texts for the five longest descriptions; 2) actions nobody used in three days and
-- that a plain NPC hardly needs are hidden from NPCs (followers keep them).
-- Also in chim_settings.sql. Rollback: docs/backups/core_action_before_trim_2026-10-04.tsv
UPDATE public.core_action SET updated_at = now(), description =
 'Really hand over what #HERIKA_NAME# owns (only when truly agreeing to give, sell after payment, or bequeath). target: "horse" (a mount standing nearby), "around" (own chests and things nearby), "house" (the home #PLAYER_NAME# stands in, if it is #HERIKA_NAME#''s), "all" (everything carried), "spell:<name>" (teach a known spell). One inventory item: Give_Item_To; gold: Give_Gold_To.'
WHERE code_name = 'GiveToPlayer';
UPDATE public.core_action SET updated_at = now(), description =
 'Creates a real item and gives it to the target (default #PLAYER_NAME#), exact English name. The Narrator: any item asked for (Daedric Sword, Fine Clothes). Other NPCs: ONLY food or drink they serve at work, ONLY after payment (Ale, Nord Mead, Wine, Bread, Sweet Roll, Beef Stew, Salmon Steak, Grilled Chicken Breast...).'
WHERE code_name = 'SpawnItem';
UPDATE public.core_action SET updated_at = now(), description =
 '#HERIKA_NAME# writes a real paper for #PLAYER_NAME# (receipt, contract, deed, permit, letter, note). target = "<paper name>: <text in Russian, signed>", e.g. Расписка: Получил от Шамана 500 септимов. Назим. Use it whenever #HERIKA_NAME# promises to write or sign something - do not just talk about it.'
WHERE code_name = 'WriteDocument';
UPDATE public.core_action SET updated_at = now(), description =
 'Only a city steward or jarl: really sell #PLAYER_NAME# the city house (target: its name). The game takes the price and gives the key and ownership at once - no walking, no office, no Give_Item_To. Use it as soon as #PLAYER_NAME# agrees to buy.'
WHERE code_name = 'SellHouse';
UPDATE public.core_action SET updated_at = now(), description =
 'Only a city steward or jarl: buy ALL furnishings and upgrades of the city house #PLAYER_NAME# owns at once (target: its name); the game takes the prices itself. Use it when #PLAYER_NAME# asks to furnish or upgrade the house.'
WHERE code_name = 'FurnishHouse';
UPDATE public.core_action SET available_to_npc = false, updated_at = now()
WHERE code_name IN ('UseSoulGaze', 'IncreaseWalkSpeed', 'DecreaseWalkSpeed', 'StartRitualCeremony', 'EndRitualCeremony',
                    'ComeCloser', 'PickupItem', 'TakeHeldItem', 'Toast', 'GoToSleep', 'Consume');
-- 3) "Earlier events" scene summaries: 6 of them (~1.8k chars each) were 9.7k of a 13k history block,
-- the real dialogue lines only 1.6k. SHORT_TERM_MEMORY_MAX was "" (= default 10); keep the 3 newest.
UPDATE public.core_profiles SET metadata = jsonb_set(metadata, '{SHORT_TERM_MEMORY_MAX}', '3') WHERE id = 1;
