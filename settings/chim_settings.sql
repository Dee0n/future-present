-- CHIM database tweaks re-applied by install.sh (idempotent).
-- CHIM updates can reset built-in actions, so keep them here.

-- Let NPCs take the gold they ask for straight from the player's inventory.
-- Off by default; without it NPCs open the trade window, where gold cannot be
-- handed over, and quests waiting for payment stall.
UPDATE public.core_action SET is_activated = true, updated_at = now()
WHERE code_name = 'TakeGoldFromPlayer' AND is_activated IS DISTINCT FROM true;

-- Narrator as game master: on request it can create/spawn NPCs, stage a
-- scene through director mode, or teleport an actor. NPCs can be told to
-- wait here.
UPDATE public.core_action SET is_activated = true, updated_at = now()
WHERE code_name IN ('CreateNewNPC', 'DirectorCommand', 'SpawnNPC', 'TeleportNPC', 'WaitHere')
  AND is_activated IS DISTINCT FROM true;

-- Taverns: staff can actually bring the food or drink the player paid for.
-- The server (herika-npc-spawn-food.patch) only lets regular NPCs spawn food
-- and drink, max 5 at a time; the narrator (game master) may spawn anything.
UPDATE public.core_action SET
    is_activated = true, available_to_npc = true, available_to_followers = true, available_to_narrator = true,
    description = 'Creates a real game item and gives it to the target. If #HERIKA_NAME# is The Narrator (game master): any item #PLAYER_NAME# asks for, by its exact English name from the descriptions database (e.g. Daedric Sword, Fine Clothes, Fine Boots). Any other NPC: ONLY food or drink #HERIKA_NAME# serves or sells at work (innkeeper, tavern staff, cook), and ONLY after #PLAYER_NAME# has paid; exact English name: Ale (эль), Nord Mead (нордский мёд), Honningbrew Mead, Black-Briar Mead, Wine, Alto Wine, Spiced Wine, Bread, Sweet Roll, Apple Pie, Eidar Cheese Wedge, Goat Cheese Wedge, Beef Stew, Vegetable Soup, Cabbage Potato Soup, Horker Stew, Venison Stew, Salmon Steak, Cooked Beef, Grilled Chicken Breast, Leg of Goat Roast. Target is #PLAYER_NAME# unless another recipient is named.',
    updated_at = now()
WHERE code_name = 'SpawnItem';

-- Narrator cheats, enabled on request: create gold, kill a target.
UPDATE public.core_action SET is_activated = true, available_to_narrator = true, updated_at = now()
WHERE code_name IN ('SpawnGold', 'KillTarget') AND is_activated IS DISTINCT FROM true;

-- NPC gifts that really change ownership in game (no "steal"). Server: ext/tes_gifts ->
-- outbox -> bridge override TESGodConsoleReport:
--   horse  -> ["tesnear Лошадь", "setownership"] (nearest such animal to the player)
--   around -> ["tesnear <giver>", "tesgive around"] (giver's things within 1500 units)
--   house  -> ["tesnear <giver>", "tesgive house"] (the interior the player stands in)
--   all    -> ["tesnear <giver>", "tesgive all"] (everything carried)
--   spell:<name> -> ["player.addspell <FormID>"] (game index)
DELETE FROM public.core_action WHERE code_name = 'GiveHorse';  -- first version, replaced
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'GiveToPlayer', 'Give_To_Player', '', 'Gave #TARGET# to #PLAYER_NAME#', true, true, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "horse | around | house | all | spell:<spell name>"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'GiveToPlayer');
-- available_to_narrator = false: this is an NPC-owns-it action ("something #HERIKA_NAME#
-- owns"), not a Narrator/god-mode action. Found set to true by accident 2026-09-29 (an
-- unrelated SQL mistake overwrote every core_action row's flags); pinned here explicitly
-- so a future re-apply of this file can't lose it again.
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = true,
    available_to_narrator = false,
    description = 'Really hand over to #PLAYER_NAME# something #HERIKA_NAME# owns, so it is no longer stolen - use it ONLY when #HERIKA_NAME# truly agrees to give, sell (after payment) or bequeath it. target: '
      || '"horse" = a horse/mount standing near #PLAYER_NAME# (a stablemaster gives or sells a horse); '
      || '"around" = #HERIKA_NAME#''s things near #PLAYER_NAME#: chests, furniture, items lying around (take anything, look into the chest); '
      || '"house" = the house #PLAYER_NAME# is standing in right now, with everything inside and its doors (only if it is #HERIKA_NAME#''s home); '
      || '"all" = literally everything #HERIKA_NAME# carries and wears; '
      || '"spell:<name>" = teach #PLAYER_NAME# a spell #HERIKA_NAME# knows (e.g. spell:Огненная стрела). '
      || 'For a single item from the inventory use Give_Item_To, for gold Give_Gold_To.',
    updated_at = now()
WHERE code_name = 'GiveToPlayer';

-- Narrator god mode: run Skyrim console commands (server: herikaQueueGodCommands
-- in herika-actions.patch -> quest action outbox -> AIAgent executes them).
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'GodCommand', 'God_Command', '', 'Done: #TARGET#', false, false, true, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "REQUIRED: one or more Skyrim console commands separated by ;"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'GodCommand');
UPDATE public.core_action SET is_activated = true, available_to_narrator = true, available_to_npc = false,
    description = 'God mode: run Skyrim console commands to change the world directly. target = commands separated by ";" (max 8). '
      || 'Actors: ALWAYS write {npc:Exact Name} exactly as the name appears in the scene (e.g. {npc:Амрен}) - the server or the game finds the actor, also people who never spoke to you; copy a hex RefID only if no name is known, or use player. '
      || 'Placeholders: {item:item name, Russian or English}, {cell:exact place name, e.g. Драконий Предел}, {spawn:creature or person name},{weather:Clear|Cloudy|Fog|Rain|Thunderstorm|Snow|Blizzard|Dark}, '
      || '{explosion:fire|frost|shock|big|huge|visual} (visual = no damage), {spawn:bandit|mage|archer|boss}, {spell:spell name, Russian or English}, {perk:perk name}, {faction:faction name}. '
      || 'tgm is a switch (on/off) - read the journal before using it again. Refused commands and real results are in your god command journal. '
      || 'NEVER guess or invent names: if an item/place/actor name is refused, or you are unsure, look it up first: find предмет|заклинание|способность|персонаж|фракция|место <words> (e.g. find предмет мантия Седобородых) - the exact existing names appear in your god command journal on your NEXT turn, use them verbatim then. '
      || 'Recipes: '
      || 'resurrect: {npc:Name}.resurrect | heal: {npc:Name}.restoreav health 1000 | fully heal, revive from unconsciousness and cure disease: {npc:Name}.heal or player.heal | dress someone: {npc:Name}.equipitem {item:exact Russian item name} - clothes may reset after a reload, this is not guaranteed permanent, do not claim it is. outfit is BROKEN (leaves the NPC naked) - never use it, never suggest it. | undress: {npc:Name}.unequipall | '
      || 'weather: fw {weather:Thunderstorm} | time: set gamehour to 22 | give: player.additem {item:Daedric Sword} 1 | level up: player.advlevel | invulnerable: tgm | '
      || 'make friend/lover: {npc:Name}.setrelationshiprank player 4 | calm: {npc:Name}.stopcombat | giant: {npc:Name}.setscale 3 | bring: {npc:Name}.moveto player | '
      || 'spawn people: player.placeatme {spawn:bandit} 6 | explosion here: player.placeatme {explosion:huge} 1 | '
      || 'rain of exploding people: player.placeatme {spawn:bandit} 6; player.placeatme {explosion:huge} 1 | '
      || 'teleport the player: coc {cell:Place or city name, e.g. Рифтен} | '
      || 'summon any creature by name: player.placeatme {spawn:Курица|Великан|Дракон|...} N (max 10); unique people are not cloned - bring the real one with {npc:Name}.moveto player, or make a new person with Create_New_NPC | '
      || 'remove someone you summoned or cloned: {near:Name}.unsummon (only works on beings created during play) | '
      || 'rain of cheese: player.placeatme {item:Cheese Wheel} 10 | slow motion: sgtm 0.3 (back to normal: sgtm 1) | '
      || 'super speed: player.setav speedmult 300 | fus ro dah: player.pushactoraway {npc:Name} 50 | tiny: {npc:Name}.setscale 0.3 | '
      || 'teach a spell: {npc:Name}.addspell {spell:Fireball} | grant an ability: {npc:Name}.addperk {perk:perk name} | join a faction (rank is REQUIRED, use 0 if unsure): {npc:Name}.addfac {faction:faction name} 0 | leave a faction: {npc:Name}.removefac {faction:faction name} | '
      || 'enchanting an item is NOT possible yet (no working command for it) - do not claim you enchanted something. | '
      || 'rewrite a character (their memory in CHIM, no ";" inside the text): {npc:Name}.character personality: new personality | {npc:Name}.character occupation: new trade/status | '
      || '{npc:Name}.character speechstyle: how they talk | feelings towards the player: {npc:Name}.relation <-100..100> <friend|romantic|grateful|admirer|rival|enemy|fearful|...> short reason; feelings between two NPCs: {npc:A}.relation to B 80 romantic reason (set both directions if mutual). '
      || 'spread news or a rumor through the current hold (every local NPC hears it for 14 days): rumor Говорят, что ... (a character occupation change spreads a rumor by itself). '
      || 'To make someone rich/noble/friendly, combine: dress them + character occupation + character personality + relation (+ rumor so family and neighbours know). '
      || 'deep mind rewrite (hypnosis: CHIM regenerates personality, goals, speech style and trade from your instruction - use it when someone must truly change, e.g. stop hating the player): {npc:Name}.hypnosis instruction in Russian, naming the player, e.g. {npc:Назим}.hypnosis благодарен Шаману за воскрешение, добр и вежлив с ним, раскаивается в прошлых ссорах; combine with relation. '
      || 'To change how someone TREATS the player (kind instead of rude), set ALL THREE, each as its own command: character personality + character speechstyle (how they now talk to the player) + character goals (what they now want) - old speechstyle/goals keep them rude otherwise. '
      || 'marry two people (one spouse each: former spouses and romances become exes, both remember the wedding, rumor spreads): {npc:A}.marry B | '
      || 'give someone a lasting memory of what happened (they will know it in every talk): {npc:Name}.remember what happened, in their words. '
      || 'change where someone spends their days (a beggar at the gate, a guard at a door, a new job spot): {npc:Name}.routine here - they will live around the spot where the player stands now; back to their old schedule: {npc:Name}.routine reset. '
      || 'Story changes: always make everyone involved REMEMBER them (remember/marry), and remove leftovers you replaced ({near:Name}.unsummon). '
      || 'Never use disable/enable on NPCs (breaks their model). '
      || 'documents and papers (deed, permit, pass, receipt, contract, letter, certificate, or a FORGERY written in someone else''s name with their seal) - a real readable paper appears in the inventory: player.document <paper name>: <full text in Russian, signed>, e.g. player.document Купчая на дом: Сим удостоверяется, что Шаман приобрёл дом в Вайтране за 5000 септимов. Подпись: Провентус Авениччи, управитель ярла | {npc:Name}.document <paper name>: <text>. Never invent item names for papers - write the document. No ";" and no line breaks inside the text.',
    updated_at = now()
WHERE code_name = 'GodCommand';

-- 2026-10-03: the model reads action descriptions from core_action, NOT from functions.php's
-- $F_TRANSLATIONS_LOCAL (edits there never reached it). Gold must not go through the
-- trade/gift window (live: Назим answered a million septims with OpenInventory2).
UPDATE public.core_action SET
    description = 'Opens the trade window to exchange ITEMS with #PLAYER_NAME#. Never use it for money: to take gold from #PLAYER_NAME# use Take_Gold_From_#PLAYER_NAME#, to give gold use Give_Gold_To.',
    updated_at = now()
WHERE code_name = 'OpenInventory';
UPDATE public.core_action SET
    description = 'Opens the gift window so #PLAYER_NAME# can hand ITEMS to #HERIKA_NAME#. Never use it for money (gold, coins, septims, payment, debt, loan): use Take_Gold_From_#PLAYER_NAME# to take gold, or Give_Gold_To to give gold.',
    updated_at = now()
WHERE code_name = 'OpenInventory2';
UPDATE public.core_action SET
    description = '#HERIKA_NAME# gives gold, coins, or septims to another actor or #PLAYER_NAME# (also when #PLAYER_NAME# asks for money and #HERIKA_NAME# agrees) - never the trade window. REQUIRED: Must include ''target'' field with recipient name and ''item'' field with amount as a number string.',
    updated_at = now()
WHERE code_name = 'GiveGoldTo';

-- Any NPC can write a real paper for the player (server: functions.php postfilter
-- WriteDocument -> tesGodGuardMakeDocument -> spawnBook, the same channel CHIM's physical
-- NPC diaries use). Owner, 2026-10-03: "чтоб они все умели какие-то документы делать".
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'WriteDocument', 'Write_Document', '', '#HERIKA_NAME# hands #PLAYER_NAME# a written paper.', true, true, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "<paper name>: <full text in Russian, signed by its author>, e.g. Расписка: Получил от Шамана 500 септимов. Назим"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'WriteDocument');
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = true,
    available_to_narrator = false,
    description = '#HERIKA_NAME# writes a real paper and hands it to #PLAYER_NAME#: a receipt, contract, deed of sale, permit, pass, letter of recommendation, IOU, note, map directions - whatever #HERIKA_NAME# would plausibly write in their role (a steward writes deeds and permits, a merchant receipts, a scholar notes). A shady character may forge one in someone else''s name. target = "<paper name>: <text>" (e.g. Расписка: Получил от Шамана 500 септимов. Назим), text in Russian, signed by its author. Use it whenever #HERIKA_NAME# promises to write, sign or issue a paper - do not just talk about it.',
    updated_at = now()
WHERE code_name = 'WriteDocument';
UPDATE public.core_action SET parameters_json = '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "<paper name>: <full text in Russian, signed by its author>, e.g. Расписка: Получил от Шамана 500 септимов. Назим"}}}'::jsonb WHERE code_name = 'WriteDocument';
-- TES-ESTATE (2026-10-03, owner: "сделай так чтоб он умел это делать"): stewards and jarls
-- really sell the city houses. Live 07:16: Proventus had no such action, "sold" Breezehome by
-- walking around and handing over a nameless item (GiveItemTo 0x001046D3). Server:
-- ext/tes_estate -> bridge tesbuyhouse (vanilla HousePurchase stage, gold checked in game).
-- Also included in chim_settings.sql. Rollback: DELETE FROM core_action WHERE code_name='SellHouse';
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'SellHouse', 'Sell_House', '', '#HERIKA_NAME# draws up the sale of the house.', true, false, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "the house: Дом теплых ветров, Высокий шпиль, Медовик, Влиндрел-холл or Хьерим"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'SellHouse');
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = false,
    available_to_narrator = false,
    description = 'Only a city steward or jarl: actually sell #PLAYER_NAME# the city''s house for sale (Whiterun: Дом теплых ветров, Solitude: Высокий шпиль, Riften: Медовик, Markarth: Влиндрел-холл, Windhelm: Хьерим). The game itself takes the price in gold and gives the key, the decorating guide and ownership - no walking, no GiveItemTo of keys. Prices (Requiem): Дом теплых ветров 3000, Медовик 4000, Влиндрел-холл 5000, Хьерим 6000, Высокий шпиль 10000. Use it once #PLAYER_NAME# agrees to buy; the result (sold / not enough gold / already owned) comes back to you.',
    updated_at = now()
WHERE code_name = 'SellHouse';

-- Live 07:21-07:23: Proventus said "follow me" four times while issuing FollowPlayer (he
-- followed the player instead). Make the direction explicit.
UPDATE public.core_action SET
    description = '#HERIKA_NAME# walks BEHIND #PLAYER_NAME# (the player leads). To lead #PLAYER_NAME# somewhere ("follow me", "I will show you the way") use TravelTo with the place instead.',
    updated_at = now()
WHERE code_name = 'FollowPlayer';
UPDATE public.core_action SET
    description = '#HERIKA_NAME# walks to a building, city, door or other location - also to LEAD #PLAYER_NAME# there ("follow me"). Name the place as it is called in the game.',
    updated_at = now()
WHERE code_name = 'TravelTo';
-- TES-ESTATE furnishings (2026-10-04, owner: "улучшения хаты все разом тоже купить бы хотел").
-- Server: ext/tes_estate -> bridge tesfurnish (per room: HD* global price, DecorateMarker.Enable,
-- OldMarker.Disable - what the vanilla steward dialogue does). Rollback:
-- DELETE FROM core_action WHERE code_name='FurnishHouse';
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'FurnishHouse', 'Furnish_House', '', '#HERIKA_NAME# orders the furnishings for the house.', true, false, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "the house: Дом теплых ветров, Высокий шпиль, Медовик, Влиндрел-холл or Хьерим"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'FurnishHouse');
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = false,
    available_to_narrator = false,
    description = 'Only a city steward or jarl, for the city house #PLAYER_NAME# already owns: buy ALL its furnishings and upgrades at once (kitchen, living room, dining room, loft, alchemy or enchanting corner - whatever that house has). The game takes each room''s price in gold itself and puts the furniture in place at once; rooms already bought are skipped. Use it when #PLAYER_NAME# asks to furnish, decorate, upgrade or improve the house; the result comes back to you.',
    updated_at = now()
WHERE code_name = 'FurnishHouse';
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
-- TES (2026-10-04): NPCs talk less among themselves after the player's line. Live 02:55-02:57: a
-- guard given an order spent the next minute agreeing with another guard ("Да, Браксек, я знаю…")
-- while the player waited. Was 50. Rollback: UPDATE conf_opts SET value = '50' WHERE id = 'RECHAT_P';
UPDATE public.conf_opts SET value = '25' WHERE id = 'RECHAT_P';
-- TES (2026-10-04): local model as a CHIM connector - Qwen3.5 4B (Q4_K_M, text only) in LM Studio on
-- the Windows host, ~3.4 GB VRAM at 14k context. docs/local-llm.md. The URL holds the WSL gateway
-- address of the host; if it changes after a reboot: bash tools/local_llm.sh
-- Rollback: DELETE FROM core_llm_connector WHERE id = 20;
INSERT INTO public.core_llm_connector (id, label, metadata, url, model, provider, driver, max_tokens, enforce_json, prefill_json, api_badge_id, json_schema, temperature, service)
SELECT 20, 'Local Qwen3.5 4B (LM Studio)', '{"lmstudio_compat": true, "extra_parameters": {"reasoning_effort": "none"}, "extra_parameters_enabled": true}'::jsonb, 'http://172.22.208.1:1234/v1/chat/completions', 'qwen/qwen3.5-4b', 'lmstudio', 'openaijson', 600, 1, 0, 2, 1, 0.7, 'openai'
WHERE NOT EXISTS (SELECT 1 FROM public.core_llm_connector WHERE id = 20);
-- reasoning_effort none: with thinking on, the model spends the whole max_tokens on it and the line is empty
UPDATE public.core_llm_connector SET metadata = '{"lmstudio_compat": true, "extra_parameters": {"reasoning_effort": "none"}, "extra_parameters_enabled": true}'::jsonb WHERE id = 20;
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
