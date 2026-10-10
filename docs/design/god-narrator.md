# God-Narrator v2: the wish compiler (design, 2026-10-09; revised 2026-10-10 after verification)

Status: design only. Nothing here is built except the index fields of part 7 (F27). Read-only work: SELECTs on the live DB `dwemer`, file reads, no LLM call,
no game run. Marks: **[ran]** = I ran the query or command today; **[read]** = I read the code or doc, did not run it;
**[not verified]** = neither.

Revision of 2026-10-10: a verifier attacked the design. Architecture B stands; the ten findings are closed in
this text, and §13 maps each finding and each owner decision of that day to the sections that changed. Builders
start with part 0, then part 1. In the repo since the first version: the guard's child rule for harm verbs
(780852c), the scene switch (b2971e9), the enriched game index (652d329, 3b9923a; loaded into the live DB the same
day, F27). The bridge is unchanged. The project was frozen on 2026-10-10 (`docs/FROZEN.md`); this file is the
state to resume from.

Language rule for every part: text sent to a model is English; text the player hears or reads is Russian; regexes
that match his speech are Russian.

Contents: 1 findings · 2 benchmark of 60 wishes · 3 where the steps went · 4 architectures · 5 recommendation ·
6 design · 7 tabletop layer · 8 contracts · 9 parts for builders · 10 assumptions · 11 production risks ·
12 not determined · 13 changes after verification.

---

## 1. Findings that constrain the design

| # | Finding | Evidence |
|---|---|---|
| F1 | 73 % of the calls of failed tasks are lookups, not deeds: 911 server lookups (`find`, `npc_info`, `relationships`, `quest_log`) + 175 game reads of 1487 calls. Writes are 395 (26.6 %), and 74 of those were refused or errored. | [ran] `tes_agent_tasks.transcript`, statuses failed + gave_up |
| F2 | The agent cannot ask "who is in X". `find kind=npc` matches a name substring only (`ext/tes_agent/worker.php:265-275`). Bulk wishes guess names one at a time. | [read] + tasks 185, 167 |
| F3 | The index can answer "who is in X" today: `actor.extra = {base, cell}` for 15810 placed actors; 181 actors sit in cells whose EditorID starts with `Whiterun`, 7 in `WhiterunBanneredMare`. It cannot answer sex, race, faction or child: `npc.extra` is `{}`; cells carry no location. Since 2026-10-10 it can: F27. | [ran] `tes_game_index` |
| F4 | Game reports are matched by "rows with id > N" (`worker.php:164-181`), not by sequence. 13 of 334 game reads returned another command's report. Task 201 step 26: `get_state player` returned Карлотта Валентия. | [ran] |
| F5 | The loose name resolver returns the wrong person: «Хельга» → Хульда (tasks 133, 167, 185), «Шаман» (the player) → Огман Магодин (13, 198, 201), «Ингун Черный Вереск» → Скульвар Черная Рукоять (185). Cause: Levenshtein ≤ 2 on any name word (`ext/tes_god_guard/functions.php:86-111`). | [ran] 11 cases |
| F6 | `set_quest_stage` never works: the tool sends `setstage`, the guard refuses `setstage` for everyone (`functions.php:1031`, `:1795`). Tasks 9, 13, 146 hit it. | [read] + [ran] |
| F7 | The agent write path skips the child filter. `tesAgentWrite` goes guard → core `herikaQueueGodCommands` (`worker.php:213-218`); only `tesWorldQueue` calls `tesChildSafeCommands` (`ext/tes_world/lib.php:424-425`). The guard checks children for `unequipall`, `unequipitem`, `giveall`, `clone`, `sex` only (`functions.php:1417, 1520, 1536, 1556`); `kill` on a child reaches the bridge, where `teskill` runs `TESMortal` + `Kill()` before its child branch (`AIAgentQuestProgressionBridge.psc:284-323`). State after 780852c: F19, F20. | [read] |
| F8 | Children are known only when CHIM has met them: `tesChildSafeIsChildRef` reads `core_npc_master.race` (`childsafe.php:11-21`). 9 rows have race `Ребенок`; 242 NPC rows in total. A child never spoken to counts as an adult. Since 2026-10-10 the index flags 53 child bases (F27); the gate still reads CHIM rows only. | [ran] |
| F9 | The worker system prompt allows jailing children: «всё прочее (арест, тюрьма, привести, наградить) с ними делать можно» (`worker.php:696`). The brief forbids it. Removed in 780852c (F19). | [read] |
| F10 | Undo of agent tasks has never run: table `tes_agent_undo` does not exist in the live DB. `tes_undo` (fast orders) has 6 rows, 0 undone. `tesAgentInverse` returns null for `moveto`, `setav`, `fw`, `set gamehour`, `placeatme` (`ext/tes_agent/lib.php:123-155`). | [ran] + [read] |
| F11 | The six "prepare Lydia" runs of 2026-10-06 (tasks 209-214) were dry runs: every write result carries `"dry_run":true`, `finish` skips its checks in dry mode (`worker.php:657-659`). 19 of 114 agent runs are dry: 1-10, 12, 43, 77, 209-214. The post-fix agent has never run against the game. The game last reported at 2026-10-06 18:03; bridge in that session: 15 (`tes_watch.bridge_ver`). | [ran] |
| F12 | A plain task may take 60 model turns and 600 s: `TES_AGENT_MAX_STEPS = 60`, loop bound `$turns < $maxSteps` (`worker.php:21, 52, 732`). ROADMAP says 10. | [read] |
| F13 | Console pace: 0.29 s median, 0.35 s p90 between reports inside a burst (12641 gaps). A 10-command sequence takes about 3 s. `prid` answered "not found" 51 times of 4880. | [ran] `tes_god_console_log` |
| F14 | Report text is cut at 500 characters (`ext/tes_god_console/preprocessing.php:35`); 85 of 14149 rows are cut. `tesinspect` lists lose their tail. | [ran] |
| F15 | Money: 114 agent runs cost $0.543 in total; a finished run $0.0040 on average (16.9 calls), a failed one $0.0053 (25.3 calls). Key limit $2, $1.96 left on 2026-10-06. | [ran] |
| F16 | What works with zero agent turns today: 57 `fast` rule orders, and the Russian-regex handlers in `ext/tes_world/preprocessing.php:107-140` (court, gods, craft, services). Each handler is PHP code; a new kind of wish means new code. | [ran] + [read] |
| F17 | The Narrator writes set programs on its own: `foreach {npc} in nearby_actors.filter(...)`, seven times, all refused (`ext/tes_agent/functions.php:29-35`). The model wants set semantics and has no legal form for them. | [read] |
| F18 | Native pieces to reuse: `herikaInvokeRolemasterCliCommand('instruction', …)` (Director scene, up to 12 nearby actors, one core model call), `('spawn', …)` (Create_New_NPC), `SpawnNPCRaw` rolecommand, `tesGodGuardRunServer` for relation, memory, marriage, jail, fine, document (`functions.php:729`), `tesGodAutosaveIfNeeded` (`:1960`), `tesWorldQueue` (child filter + worn tracking), the delayed queue `tes_fest_queue` (`festival.php:22-56`), `chimRegisterPromptInjection`. | [read] |
| F19 | After commit 780852c the guard refuses 18 harm verbs when the target or a ref named in the body is a child (`functions.php:1572-1586`), and `worker.php:696` no longer allows jailing. Server verbs are still open: only `jail` asks (`functions.php:789`); `fine`, `marry`, `order`, `relation`, `remember`, `character` do not (`:801-856`). Live: guard log 1375-1376, `{npc:Дорти}.fine 1000000`, verdict ok; Дорти is a child. | [read] + [ran] |
| F20 | `tesChildSafeCommands` follows one selection form, `prid <8 hex>` with a lower-case `prid` (`childsafe.php:29`). It does not follow `PRID`, a short ref, `tesnear <name>` (sent by `tesGodGuardQueueNearby`, `functions.php:2146`) or `<ref>.<command>`. After a child selection it drops 16 verbs by a blacklist (`:33`); `setav health 0`, `setrestrained 1`, `teshold`, `moveto` pass. | [read] |
| F21 | The DB runs the C locale (`datcollate = C`). `race ~* 'реб'` and `race ILIKE '%реб%'` match 0 rows; `position('ебенок' in race) > 0` matches the 9 children. | [ran] |
| F22 | Refids are shared: 242 rows in `core_npc_master`, 206 distinct refids, 60 rows share one (`000D0FF4` carries five guard names). The child lookups read one arbitrary row: `LIMIT 1` without an order (`childsafe.php:17`, `functions.php:159`, `tes_crime/lib.php:37`). | [ran] + [read] |
| F23 | Cells whose EditorID starts with `Whiterun` hold 181 placed actors, 91 named. 30 of the named are unknown to CHIM, among them quest refs and corpses: Амон Мотьер (`AmaundMotierreEndRef`, placed in the Bannered Mare), Легат Рикке (`CWBattleRikke`), Знатный гость (`DBRecurringTarget1Whiterun`), a second Скьор (`Skjor2REF`), `TreasCorpseSilverHandNordFemale`. 64 refs are both placed there and known to CHIM, 7 of them children. | [ran] |
| F24 | A goal reaches the worker in the Narrator's words: «собрать всех жителей Вайтрана в Гарцующей кобыле» (185, infinitive), «собрать женщин в Гарцующей кобыле и ждать…» (168, no city), «Шаман хочет, чтобы все люди в Вайтране появились рядом с ним» (197). The v1 recipe regex wanted an imperative, then the people, then a capitalised city, then «в <место>»: 0 of 3. Speech-to-text garbles places in the player's own lines: «горчующие кобыли», «Айтране», «Ваитранвле». | [ran] |
| F25 | Bridge: the lock holder stamps `TESConsoleLockAt` once per command of a sequence (`psc:117`); another row force-takes the lock when the stamp is 30 s old (`psc:80-91`). A `prid` that is not found ends the whole sequence, and the rest is logged as one row `…@@error: aborted, target not found` (`psc:118-122`), so a closing marker never arrives. | [read] |
| F26 | `tes_agent_undo` is created with a six-column shape in two places (`ext/tes_agent/lib.php:164`, `tools/ensure_playthrough_tables.php:24`). The table does not exist in the live DB. | [read] + [ran] |
| F27 | The enriched index went live on 2026-10-10 while this revision was written (commits 652d329, 3b9923a): 39411 `npc` rows carry `sex`, `race`, `unique`, `essential`; 53 carry `child`; 866 carry `tpl` (traits inherited from a template; `lvln` = a leveled template, traits unknown); 297 rows of kind `race` (8 child races), 220 `shout`, 113 `word`; 1974 cells carry `loc`, 755 locations carry `parent`. Missing for this design: `dis` and `xesp` on placed actors, `anchor` on cells. `unique` alone does not remove quest refs: Амон Мотьер, Знатный гость and Алик'рский пленник are unique; the staging cells `WhiterunAttackStart03` and `WhiterunExterior13` carry `loc` = `WhiterunLocation`. | [ran 16:20] |
| F28 | Scenes: since b2971e9 the guard refuses `sex` and `teslove` except `stop` while `TES_WORLD_INSTANT_SCENES` is off (`functions.php:1532, 1554`); the Narrator's instruction line says so (`ext/tes_agent/context_pre.php:19`). | [read] |

---

## 2. Benchmark: 60 wishes a dungeon master grants

Today: **W** works · **P** partly · **F** fails · **X** impossible by engine. Evidence: (L) seen in live logs, (C) code path
read, not seen live. After: **W** works · **W?** works if an unverified engine behaviour holds · **S** substitute or
partial, said honestly · **O** out of scope by the owner's decision of 2026-10-10 · **H** a hidden check that waits
for the owner's yes (part 9). Needs: **s** server only · **b** bridge v17 · **i** index enrichment.

| # | Wish (as spoken) | Today | Why | After | Needs |
|---|---|---|---|---|---|
| **A** | **Creatures and people** | | | | |
| 1 | «Призови дракона над Вайтраном» | W (L) | `player.placeatme {spawn:Дракон}`; 105 `placeatme` reports in the console log. Server never checks that it appeared. | W: spawned refs come back in the report | b |
| 2 | «Создай мне спутницу, эльфийку-лучницу Тирию» | W (C) | native `Create_New_NPC`: template + name + profile (engine limit 3: no new ActorBase) | W | s |
| 3 | «Пусть на рынке появятся три торговца-каджита» | F | `Spawn_NPC` wants an English SNQE template key; `{spawn:}` matches record names only | S: three Khajiit from a base found by race; no shop (vendor containers cannot be made) | b i |
| 4 | «Преврати Назима в курицу» | F | `setrace` is not in the guard list (`functions.php:1017-1024`) | W? `tesrace`, original kept for undo | b |
| 5 | «Воскреси всех, кто погиб в этом бою» | F | no selection of the dead nearby; one `resurrect` per turn | W: `tesscan` flag D + `tesfor … :: resurrect` | b |
| **B** | **Items** | | | | |
| 6 | «Дай мне даэдрический меч» | W (L) | `additem`, 362 reports; native `Spawn_Item` | W | s |
| 7 | «Зачаруй мой меч на огонь» | F | guard: "no command applies an enchantment" (`functions.php:1314`) | W? `tesenchant` (SKSE `WornObject.CreateEnchantment`) | b |
| 8 | «Сделай уникальный меч „Клык Шамана“ с уроном 100» | X | no new persistent forms at runtime (facts §3.3, DPF absent) | S: the equipped weapon is renamed and tempered (`tesitem`) | b |
| 9 | «Раздай каждому в таверне по бутылке мёда» | F | bulk: one `additem` per person per turn | W, adults only | b |
| 10 | «Положи 500 золотых в этот сундук» | F | a container has no name; a bare RefID outside `core_npc_master` is refused (`functions.php:1844`) | W: crosshair ref from `tesscan cross` | b |
| **C** | **The player himself** | | | | |
| 11 | «Сделай меня богом воровства» | P | tasks 1-4 (dry): 1 of 4 finished, 34 calls; task 96 (live) 26 calls, 440 s | W: one planner turn, kit resolver | s |
| 12 | «Вылечи меня и сними болезни» | W (L) | `player.heal` → `tesheal` | W | s |
| 13 | «Сделай меня вампиром» | F | needs the game's quest script | W? `tesbecome` (Requiem may override) | b |
| 14 | «Сделай меня великаном вдвое выше» | W (L) | `tesscale`, 193 `setscale` reports | W | s |
| 15 | «Научи меня крику Огненное дыхание» | F | shouts and words are not indexed; `teachword` not allowed | W | i |
| **D** | **Other characters: state and behaviour** | | | | |
| 16 | «Пусть Назим меня боится и уважает» | W (L) | relation + memory, tasks 8, 12 | W | s |
| 17 | «Сделай Лидию моей спутницей» | W (L) | `tesfollow`, 37 reports | W | s |
| 18 | «Подготовь Лидию к бою с драконом» | P | finished in dry mode only (209-214), 24-35 calls, 16-26 of them `find` | W: one turn | s |
| 19 | «Пересели Олаву в Рифтен» | F | `settle_here` anchors only where the player stands | W: move to an anchor in the cell + `tesroutine self` | b i |
| 20 | «Заставь стражника станцевать» | F | `playidle` not in the guard list (tes_world sends it itself, 112 reports) | W: op `idle`, a data table of idles | s |
| **E** | **Groups and crowds** | | | | |
| 21 | «Собери всех жителей Вайтрана в „Гарцующей кобыле“» | F | task 185: 60 calls, 42 `find`, zero moves | W, capped at 40 per wish (15 on bridge < 17), the rest named honestly; people CHIM never met wait for the index | b i (s slow) |
| 22 | «Пусть все люди Вайтрана появятся рядом со мной» | F | task 197: 36 `npc_info`, 20 moves, limit | W, same cap | b i (s slow) |
| 23 | «Раздень всех взрослых в зале» | F | task 167: 60 calls, 40 `find`, one person done. Works only as a ruler's spoken order for ≤ 8 nearby (`tesWorldGroup`) | W | b (s slow) |
| 24 | «Пусть вся стража Вайтрана меня уважает» | F | no selection by faction | S: the guards CHIM knows now; a standing rule for each guard met later | s i |
| 25 | «Вылечи всех раненых вокруг» | F | no scan with health | W | b |
| 26 | «Все по домам, разойдитесь» | P | feast guests only (`tesRealmGatherTick`) | W: `teshome` on any set | b |
| **F** | **Places and travel** | | | | |
| 27 | «Перенеси меня в Рифтен» | W (L) | `coc {cell:}` with the populated-cell rule | W | s |
| 28 | «Перенеси меня и Лидию на Глотку Мира» | F | a sequence across a loading screen is unverified; no "wait for arrival" step | W? step `await` on `tesworld` | b |
| 29 | «Создай новый остров с подземельем» | X | no new locations or geometry (facts §3.1) | S: an existing cell, renamed in the campaign memory, plus a door portal | s |
| 30 | «Открой портал отсюда в мой дом» | F | ScriptProxy `SpawnDoor` is not exposed to the Narrator | W? | s |
| **G** | **Time and weather** | | | | |
| 31 | «Сделай грозу и полночь» | W (L) | task 8 | W, old weather kept for undo | s |
| 32 | «Перемотай время на три дня вперёд» | F | guard allows `set gamehour` 0-23.9 only | W? `testime 72` | b |
| 33 | «Пусть всегда идёт снег» | F | no standing rules; `fw` holds until the next weather roll | S: a standing rule re-applies snow | s |
| **H** | **Quests and plots** | | | | |
| 34 | «Что у меня по заданиям?» | W (L) | `ask:` → `quest_log`, task 10 in 5 calls (table `quests` holds 2 rows) | W | s |
| 35 | «Заверши за меня задание „Перед бурей“» | F | F6: `setstage` always refused | S: stage set after a named save and a spoken confirmation; rewards and scripts are not replayed (facts §3.6) | s b |
| 36 | «Дай мне задание: найти пропавшего торговца в пещере» | F | no path from a wish to a quest (ROADMAP item 6) | S: a plot in campaign memory, a letter, a spawned target, a completion watcher; no journal entry | s b |
| 37 | «Пусть Изольда влюбится в меня, и мы поженимся» | W (L) | relation + `marry` (`tesGodGuardMarry`); guard log 1504, verdict ok | W | s |
| 38 | «Сделай меня ярлом Вайтрана» | W (L) | title through `player.title` (ROADMAP ✅); task 13 failed on the faction route | W | s |
| **I** | **Combat encounters** | | | | |
| 39 | «Пусть на нас нападут пятеро бандитов» | W (L) | `player.placeatme {spawn:бандит} 5`, lands on the player's feet | W, at a distance | b |
| 40 | «Засада: трое лучников за спиной в тридцати шагах» | F | `placeatme` has no offset | W | b |
| 41 | «Пусть стражник и Назим подерутся» | F | `startcombat` not in the guard list; `tesduel` belongs to executions | W: `tesfight` | b |
| 42 | «Останови бой, все успокойтесь» | W (L) | `player.pardon`, `tespeace` | W | s |
| 43 | «Сделай этого дракона моим союзником» | F | a creature CHIM does not know; ROADMAP item 11 | W? crosshair ref + pacify | b |
| **J** | **Dice and skill checks** (owner 2026-10-10: no dice in combat, none shown) | | | | |
| 44 | «Брось d20» | F | the model invents the number | O: no dice are shown | – |
| 45 | «Уговариваю стражника пропустить меня» | F | no check mechanics | H: hidden check from Speechcraft and the guard's affinity | s |
| 46 | «Пробую вскрыть этот замок: проверка Взлома» | F | same | O: the engine's lockpicking decides | – |
| 47 | «Кто кого перепьёт: я или Вилкас» | F | no opposed check | O: not one of the four kinds; the feast games of `festival.php` stay | – |
| 48 | Narrator settles a persuasion, a bribe, a lie or a con on its own | F | no channel | H: `check:` prefix, hidden | s |
| **K** | **Rewards and punishments** | | | | |
| 49 | «Награди Лидию 500 золотых и новым мечом» | W (L) | `give_items` | W | s |
| 50 | «Посади Назима в тюрьму на три дня» | W (L) | `tes_crime`, fast orders 159, 183 | W | s |
| 51 | «Оштрафуй Белетора на 1000» | W (L) | `fine`; guard log 1377, verdict ok. Log 1375 fined Дорти, a child (F19) | W, never a child | s |
| 52 | «Изгони Назима из Вайтрана навсегда» | F | no op; nothing enforces "навсегда" | W: relocate + a standing rule | b |
| **L** | **Scenes with several actors** | | | | |
| 53 | «Пусть Балгруф при всех отчитает Провентуса» | W (C) | native `Director_Command`; through the agent 36-44 calls (tasks 14, 24) | W: op `director` hands the brief to the Director | s |
| 54 | «Устрой свадьбу Изольды и Микаэля в храме» | F | gather + marry + scene in order | W | b |
| 55 | «Суд над Назимом со свидетелями» | W (L) | `court.php` | W | s |
| 56 | «Караван каджитов, на него нападают бандиты» | F | two groups, positions, mutual hostility | W? | b |
| **M** | **Undoing** | | | | |
| 57 | «Верни как было» | P | code exists; never exercised (F10) | W | s |
| 58 | «Отмени всё, что ты сделал за последние пять минут» | F | undo covers the last task only | W | s |
| 59 | «Воскреси казнённого, и пусть он всё забудет» | F | no op removes a memory | S: he lives and loses the memories this system wrote; CHIM's own event log stays | s |
| 60 | «Верни прежнюю погоду и время» | F | no before-state is recorded | W | b |

**Today: 19 work, 4 partly, 35 fail, 2 impossible.** Of the 19, 17 have live evidence (L); rows 2 and 53 rest on a
code path only (C).
**After (design target, not a measurement): 49 work, 8 substitute, 3 out of scope (44, 46, 47).** Of the 49, two
are hidden checks that wait for the owner's yes (45, 48), so 47 are firm. Eight carry "W?" (4, 7, 13, 28, 30, 32, 43,
56): they rest on an engine behaviour nobody has run. 24 of the 49 need bridge v17 or the index; 25 work on bridge
15/16. Rows 21-23 work on the old bridge too, one person per sequence at about 2 s each, capped at 15.
No row needs a scene to be started: scenes belong to SHARMAT and no op starts one (§6.5).
The index fields that rows 3, 15 and 24 need are live since 2026-10-10 (F27); rows 19, 21 and 22 wait for `dis`,
`xesp` and `anchor` (the rest of part 7).

The benchmark lives on as `tools/fixtures/dm_bench/NN.json` (part 8): one reference plan per row. It is the regression set.

---

## 3. Where the steps went: seven real transcripts

All from `tes_agent_tasks.transcript` [ran]. A "call" is one tool call; the transcript does not record model turns.

| Task | Goal | Calls | Where they went | Root cause |
|---|---|---|---|---|
| 185 failed | «собрать всех жителей Вайтрана в Гарцующей кобыле» | 60 | `find` 42, `npc_info` 16, `teleport_player` 1 (refused), `relationships` 1. **Zero writes.** Calls 8-20 guess first names: Берг, Харальд, Йольдис, Отра, Аудур, Ботви. | F2: no "who lives in X". F5: «Ингун» returned Скульвар. |
| 197 failed | «все люди в Вайтране появились рядом» | 64 | `npc_info` 36 (one per person, to learn who exists), `move_npc` 20 (one per person; 4 refused as jailed), `console` 3 (`unjail`), `find` 3. | No set op, no bulk move. Step 55: the report for Синмир shows `prid 0001A684` and `teshold 0`: another row's commands (F4). |
| 167 failed | «все женщины в Гарцующей кобыле были раздеты» | 60 | `find` 40 (tries "Кобыла", "Bannered Mare", "трактирщица", "служанка", "a"), `npc_info` 10, `get_state` 5, one `unequipall`. | F2. Step 1: `inspect_here` returned `setav speedmult 0`: a foreign report (F4). Steps 52-54: "не удалось поставить в очередь" for people CHIM never met. |
| 201 failed | «15 гостей возвращены к ярлу, гулянка продолжается» | 60 | `find` 27, `npc_info` 11, `quest_log` 10 (ярл, гулянк, гост, пир, празд…), `inspect_here` 3. Zero useful writes. | The goal is a statement, not an order; the model searched for a quest. Step 26: `get_state player` answered for Карлотта (F4). Step 36: `npc_info Шаман` answered Огман Магодин (F5). |
| 146 failed | «Убей Эбонитового воина окончательно» | 60 | `console` 21, of them 11 spellings of `setessential` (refused, repeated or "Invalid actor base"), `find` 19 (his armour, spells named "защит"), `npc_info` 6 to re-read "alive". | No verified kill primitive; the answer to "is he dead" came from a stale profile. The model went on guessing console syntax. |
| 176 failed (patrol) | «Патруль закона…» | 12 | `npc_info` 12. Nothing else. | The patrol got a list of names and spent the whole budget reading profiles for sex and age. A selector answers that in one SQL. Same shape: 177, 180, 186, 188, 153. |
| 165 failed | «Отдать Шаману всё мясо» | 18 | `get_state` 7, `check` 6 (`getitemcount` per person), `inspect_here` 3, `find` 2. | Probed everyone nearby for one item. Steps 13 and 18: the `check` answer is a `tesinspect` report and an `equipitem` of another row (F4). |
| 210 done (dry) | «подготовь Лидию к бою с драконом» | 35 | `find` 26: one per slot (body, head, hands, feet, greatsword, battleaxe, shield, amulet, ring), then again by exact name; perks per skill; potions. Writes 5, all dry. | "Best item per slot" is a server sort the model does by hand, twice. |

Totals over failed and gave-up runs: lookups 61.3 %, game reads 11.8 %, writes 26.6 %, finish 0.4 %. Over finished runs:
48.5 / 16.8 / 27.8 / 7.0. Finished runs also spend two thirds of their calls finding things.

---

## 4. Architectures

Criteria in the brief's order: (1) benchmark coverage, (2) model turns per wish, (3) verified outcome and honest
failure, (4) safety and undo, (5) a new kind of wish without new code, (6) no core change, one bridge version.

### A. Loop agent plus composite tools (ROADMAP item 2)

Keep `worker.php`. Add tools: `select_people`, `bulk_move`, `bulk_strip`, `equip_best`, `give_list`.
- Cost: about 300 lines in `worker.php`. No bridge change needed for a first cut.
- Turns: observe → act → finish is 3 at best; bulk 4-10; every turn resends a growing transcript.
- Cheapest failure: the model keeps wandering. Task 201 spent 60 calls on a goal it misread; task 146 spent 11 on
  `setessential` spellings. A composite tool does not stop that. Each new kind of wish is a new PHP tool: criterion 5 fails.
- Best when: the next action depends on what the last one returned, for most wishes, and model turns cost nothing.
  The transcripts say the opposite: the lookups were questions the server could answer alone.

### B. Wish compiler: one plan, server does the rest (recommended)

The model writes one declarative plan: steps of `op + selector + arguments`. The server resolves selectors against the
index, the CHIM DB and one game scan; expands the plan into short paced sequences; verifies each target by a state
probe; journals the inverse; hands counts to the Narrator. Ops, kits, recipes, encounters and idles are data files.
- Cost: about 1500 lines of new PHP in new files, one bridge version, one index rebuild.
- Turns: 0 agent turns for a recipe hit, 1 for a planned wish, 2 with a repair, hard cap 3. Bulk costs the same.
- Cheapest failure: the wish does not fit the catalog. The compiler says which step and why before anything touches
  the game; one repair turn; then an honest "не могу: …". Second: the cheap model writes invalid plan JSON.
- Best when: most wishes are "select, apply, verify" over facts the server holds. F1 and section 3 say so.

### C. In-game interpreter: the plan runs inside Papyrus

The bridge gains a small VM: `tesdo all npc in cell where female adult : unequipall`, with its own verification.
- Cost: large Papyrus; a string parser nobody can test without the game.
- Turns: as B.
- Cheapest failure: every new op or fix is a recompile and a game restart, forever: criterion 6 ("one bridge version")
  and criterion 5 fail. The VM sees neither unloaded actors nor the CHIM DB (relations, memories, jail).
- Best when: the server-to-game channel is the bottleneck and wishes touch loaded actors only. The channel does
  0.29 s per command (F13); the bottleneck is model turns.

### D. Narrator-native typed tools over the core `funcret` loop

Add typed rows to `core_action`, raise the follow-up depth, let the Narrator model loop itself (`docs/narrator-agent.md` §6, steps 3-5).
- Cost: core edits (`lib/core/action_catalog.php` depth, a `server_query` handler in `main.php`); every turn is a full
  Narrator prompt, about 9250 tokens (`PLAN-2026-10-09.md`, P11).
- Cheapest failure: a CHIM update wipes local core commits (owner's note, `tools/restore_core.sh`). Criterion 6 fails.
- Best when: upstream CHIM ships `server_query` and deeper follow-ups itself.

### E. Rules only: more Russian-regex handlers in `tes_world`

- Turns: 0. This is the only thing that works without failures today (F16).
- Cheapest failure: a phrase that misses the regex falls into plain chat, silently. Each wish is new PHP: criterion 5 fails.
- Best when: the vocabulary of wishes is small and fixed. "Make everything possible" is the opposite.
- B absorbs it: a recipe is a regex plus a plan template, as data.

| Criterion | A loop + composites | **B compiler** | C in-game VM | D core loop | E rules |
|---|---|---|---|---|---|
| 1 coverage of the 60 | about 35: bulk partly, no dice, no encounters | **49 + 8 substitutes, 3 out of scope** | about 30: no DB, no unloaded actors | as A | about 25 |
| 2 turns per wish | 3-10 | **0-1, cap 3** | 0-1 | 2-6 at 9k tokens | 0 |
| 3 verified outcome | `finish.expect`, items and perks only | **per target, every op** | in game only | none | 3 kinds (`verify.php`) |
| 4 safety, undo | scattered (F7) | **one gate, three layers, before-state** | bridge only | guard | per handler |
| 5 new wish without code | no | **yes: JSON** | no | no (SQL + core) | no |
| 6 no core, one bridge | yes | **yes** | no | no | yes |

---

## 5. Recommendation

**Build B, the wish compiler.** It is the only option that passes criteria 1, 2 and 5 together, and it keeps what
already works: the `goal:` hand-off, the guard for raw console text, `tesWorldQueue`, the fast orders, the gods.

Main rejected alternative: **A**, the roadmap's own plan. It leaves the model in a loop, so bulk wishes and wandering
stay expensive, and each new wish is new PHP.

The loop worker stays as an escape hatch (`--loop`, 6 turns) for wishes the planner marks `"explore": true`.

Verified 2026-10-10: the recommendation stands. What the verifier found is closed in §6-§9; §13 lists it.

---

## 6. Design

### 6.1 Flow and turn budget

```
player speech
  │  ext/tes_world/preprocessing.php: existing handlers (court, gods, fast orders)             0 model calls
  │  ext/tes_agent/preprocessing.php (new, part 8): rule tick; hidden-check hook (part 9, off)  0 model calls
  ▼
Narrator model turn (always paid; unchanged)
  │  God_Command target: "goal: <words>" | "ask: <question>" | "check: …" (part 9, off) | console text
  │  console text → tes_god_guard → child gate (§6.8) → core queue
  ▼
ext/tes_agent/functions.php → tesAgentStart(goal, …, said) → worker.php
  1 recipe (dm/recipes.json) on the goal, then on the player's own line     → plan        0 agent turns
  2 else planner: perception pack + catalog → submit_plan                    → plan        1 turn
  3 bind (dm/exec.php tesDmBind): one tesworld + tesscan round trip when a selector needs the game
  4 compile (dm/compile.php, resolver from dm/select.php): errors? → one repair turn      +1
  5 run, per step: pre-probe → journal rows → send through the transport → verify → update the rows;
    a deferred step (§6.4) is rendered here from the targets of the step it names
  6 outcome JSON, Russian fact line
  ▼
Narrator Instruction with counts and names (existing tesAgentNarratorSay)                  1 Narrator call
```

| Wish kind | Agent turns today | Agent turns after |
|---|---|---|
| Recipe hit: «бог воровства», «собрать всех …», «раздай всем …» | 24-64 calls, turns unknown | 0 |
| Planned wish, single or bulk | 6-64 calls | 1 |
| Plan with a compile error | n/a | 2 |
| Exploring wish (`explore`) | up to 60 turns (F12) | ≤ 6 (loop) |
| Hidden check (part 9, off until the owner confirms) | none | 0 |
| Hard cap | 60 turns | 3 (plan), 6 (loop) |

Estimated planner prompt: rules 380 tokens + catalog about 1750 (69 ops) + perception pack ≤ 500 + goal; output ≤ 500.
At the measured $0.00024 per call the planned wish costs about $0.0005-0.001 against today's $0.0040-0.0053.
[not verified: no model call made]

### 6.2 Names: the lemma step and the perception pack

Russian speech puts names into case forms («Назима», «Лидию», «в Гарцующей кобыле») and speech-to-text writes them
small and garbled (F24). No selector compares a spoken form with `=`. One function resolves every spoken name:

`tesLemmaMatch(string $spoken, array $candidates, array $opts = []): array` in `ext/tes_world/lemma.php` (part 0)
returns `['hits' => [candidate, …], 'pass' => 0|1|2|3|-1]`. A lemmatiser for free text is not needed: the candidate
list is finite, so both sides are reduced to stems and compared.

- Normalise: lower case, ё → е, the `[…]` tail of a CHIM name dropped («Джон [Стражник Вайтрана]» → «джон»).
- Stems of a word: the word itself and the word minus each ending of the fixed list, while ≥ 2 letters stay:
  `ами ями ого его ому ему ыми ими ьего ьему ьем ьим ьей ою ею ой ей ую юю ая яя ое ее ые ие ый ий ым им ом ем ам ям ах ях
  ов ев ью ья ье ьи а я у ю ы и е о ь й`.
- Two words match when they share a stem that is ≥ max(2, len(candidate) − 4) and ≥ len(spoken) − 4 letters.
- Pass 0: every spoken word matches a different word of the candidate. All words of the candidate covered = a full
  hit; full hits beat partial hits. A partial hit needs one matched word outside the title list
  (`ярл командир капитан легат стражник хускарл сын дочь старший старшая те из`), so «ярла» alone is nobody.
- Pass 1: the same after dropping title words from the spoken form. Pass 2: the first remaining spoken word alone
  («Алфильд Дочери Битвы» → Алфильд Дочь Битвы).
- Pass 3, only with `$opts['heard'] = true` and only on a short list (people near the player; places of the current
  hold plus the cities): the existing `tesWorldHeardName` rule (`ext/tes_world/lib.php:162-205`): letter distance ≤ 2
  on words of 6+ letters. It never runs against the whole table (F5).
- The caller needs exactly one hit. Several → error `ambiguous` with ≤ 5 suggestions. None → error `unknown` with
  `tesGodGuardSuggestNames`.

[ran, prototype in PHP on live names, read-only] 40 of 40 cases: «Назима» → Назим, «Микаэля» → Микаэль, «Олаву» → Олава
Немощная, «Балгруфа» and «Ярла Балгруфа Старшего» → Ярл Балгруф Старший, «Кая» → Командир Кай, «Карлотту Валентию»,
«Йорлунда Серую Гриву», «Рию», «айрилет»; «Хельгу» → nobody among the 242 CHIM rows and Хельга in the index, never
Хульда; «Ингун Черный Вереск» → nobody in CHIM, herself in the index, never Скульвар; «Вайтрана», «Вайтране» →
Вайтран; «Гарцующей кобыле» → Гарцующая кобыла; «Драконьем Пределе» → Драконий Предел; «Пьяном охотнике» → Пьяный
охотник; «таверне» → 14 places (ambiguous, as it should be); «ярла», «стражника» → nobody.

Candidate lists for people, in this order; the first list with a hit wins:
1. the player: `PLAYER_NAME` and «я меня мне мной игрок» → `player` (F5, «Шаман»);
2. people near the player (last `infonpc_close` event, as `tesWorldNearbyNames`), pass 3 allowed;
3. `core_npc_master.npc_name`, 242 rows; generic rows are left out when the spoken form is one word. Generic = a row
   without a `[…]` tail whose refid starts with `FF` or is shared with another row: «Норд», «Имперец»,
   «Женщина-редгард», «Пленник», «Стражник Вайтрана» [ran]. A person with a refid of his own stays, even when the index
   places a second actor of that name (Скьор: `0001A691` and the mod's `3301C105`);
4. names of placed actors in `tes_game_index` (kind `actor`, 1624 distinct names), passes 0-1 only; a name carried by
   more than one placed ref is error `ambiguous`.

`dm/perception.php` builds, from the goal text and the DB, what the model used to fetch call by call:
- people in the goal: every word of 4+ letters goes through lists 1-3 with pass 0 and the rule "a single word must
  be the person's call name" (`tesWorldShortName`, `lib.php:207`). On 270 real lines (1549 words) the unrestricted
  match hit 34 distinct words, among them «женщин», «воина», «руки», «вайтрана» [ran]: surnames and generic rows; the
  call-name rule and the generic filter remove those. Each person: canonical name (nominative), known to CHIM or
  not, alive, age, jailed, relation. The planner copies the canonical name.
- places in the goal: 1-3 consecutive words against named cells, locations and worlds → cell EditorID, resident count,
  anchor kind (§6.3).
- who is near, current hold, interior or exterior.
- the player's sheet for a "make me …" wish (cached `tesstate`, key `dm_sheet`, 120 s).

### 6.3 Selectors (`dm/select.php`)

A selector resolves to a list of targets of one shape (§8.2). Every target carries `age`: `adult`, `child` or
`unknown`, from `tesChildSafeAge` (§6.8).

Actor sources:
`"player"` · `{"name":"Лидия"}` · `{"names":[…]}` · `{"near":true,"radius":3000}` · `{"in":"Вайтран"}` ·
`{"in":"here"}` · `{"in":"hold"}` (the city of the current hold, `tesWorldCurrentHold`, `lib.php:63`) ·
`{"cross":true}` · `{"step":"s1"}` and `{"spawned":"s2"}` (deferred, §6.4).

Filters, all optional:

| Filter | Values | Answered by | When the source cannot answer |
|---|---|---|---|
| `sex` | male, female | CHIM `gender`, index `sex` (F27), scan M/F | target skipped, counted as `unknown_sex` |
| `alive` | default true; `false` = the dead | CHIM `is_dead`, scan D | DB value used, the probe verifies |
| `age` | `adult` (forced for ops with child policy `never`), `any` | `tesChildSafeAge` | `unknown` is skipped for `never` ops |
| `role`, `role_not` | guard, merchant, court, follower | CHIM name tag and occupation, scan G/T, index factions (F27) | skipped, `unknown_role` |
| `race`, `faction` | Russian name | CHIM `race`; index (F27) | compile error `needs_index` when the index has no kind `race` |
| `relation` | `{min,max}` | RelationshipManager | known people only |
| `jailed` | bool | `tes_crime_jail` | n/a |
| `hp` | `{min,max}` percent; `"wounded":true` = max 99 | scan only | compile error `filter_needs_scan` |
| `hostile`, `in_combat`, `teammate`, `essential`, `unique` | bool | scan flags H K T E U | compile error `filter_needs_scan` |
| `person` | default true (drops scan flag A, creatures) | scan; else the row has a humanoid race | n/a |
| `not` | names | lemma step | n/a |
| `limit`, `order` | int; nearest, random | scan distance | `nearest` without a scan = index order |

Scan filters work with `near`, `in: here` and `cross`; with another source they are a compile error whose suggestion
is "use near". Rows 5 and 25 (the dead, the wounded) therefore need bridge 17.

Resolution rules:
1. `near`, `in: here`, `cross`: the `tesscan` taken by `tesDmBind` (§6.4). On bridge < 17: the last `infonpc_close`
   event (≤ 180 s) joined with `core_npc_master`; `cross` and the scan filters are compile errors with the reason.
2. `in: <place>` returns residents, not everything placed there (F23). The place name goes through the lemma step;
   the city wins over the hold of the same name: `<Base>Location` where `<Base>World` exists (`Whiterun`), the rule
   `tes_god_guard` uses for `coc` (`functions.php:1229-1252`).
   - Tier A (today's index): refs that are both placed in a cell whose EditorID starts with `<base>` and present in
     `core_npc_master`. CHIM only stores people who stood near the player, so quest refs that were never enabled,
     corpses and battle staging refs drop out. Whiterun: 64 refs, 7 of them children [ran].
   - Tier B (needs `dis` and `xesp`, the rest of part 7): tier A plus placed actors CHIM never met whose base is
     `unique`, whose race is known and not a child race, whose ref is neither initially disabled (`dis`) nor has an
     enable parent (`xesp`), and whose cell location descends from the place's location. `unique` and the location
     are live today and are not enough: Амон Мотьер is unique and stands in the Bannered Mare (F27).
   - The named actors of the place outside the tier in use are counted in the outcome («ещё 30 не встречал») and never
     acted on.
3. One target per ref. A refid shared by several CHIM rows (60 of 242 rows, F22) gives one target with `shared: true`
   and the name «стражник». Ops with `harm: true` skip a shared target (`skipped: shared_ref`) unless it comes from the
   scan of this task, where the engine reported that ref now.
4. Child matching in SQL never uses `~*`, `ILIKE` or `lower()` on Cyrillic (F21). The one predicate is
   `position('ебенок' in race) > 0 OR position('ебёнок' in race) > 0 OR position('hild' in race) > 0`; PHP-side code uses
   `tesChildSafeAge`.

Items: `{"name":"…"}` (`tesGodGuardResolveItem`) · `{"formid":"…"}` (must exist in the index) ·
`{"best":{"type":"armor","slot":"body","armor_class":"heavy","sort":"armor_rating"}}` (the query `tesAgentFind` builds) ·
`{"kit":"dragon_fighter"}` · `{"match":"мясо","type":"food"}` (index items whose name stem matches, ≤ 12 forms) ·
`{"gold":500}`. Every item object takes `"n"`: a count 1-100000, default 1; `"all"` is legal for op `take` only and is
turned into a number by the pre-probe `item:<form>`.

Places: `{"place":"Гарцующая кобыла"}` → `{cell, anchor, anchor_kind, name}`.
1. Name → cell: lemma step over named cells and locations (829 and 760 named rows [ran]). Rows with one name: the
   lowest FormID, the original («Гарцующая кобыла» is `0001605E` and a mod copy `5C2E6E71` [ran]); it is the order of
   `tesGodGuardIndexUnique` (`functions.php:140`). A common word with several different places → places of the current
   hold (by `loc` and `parent`, F27) or error `ambiguous`.
2. Anchor, the first that exists:
   a. `cell.extra.anchor` (part 7): a static marker or a load door of that cell. `MoveTo` it puts people in the cell.
   b. the player, when `tesworld` reports the player in that cell.
   c. a host: an adult, alive, not jailed person known to CHIM, with a non-shared ref placed in that cell, lowest
      FormID. Before anyone moves, a probe confirms that the host stands in that cell (`tesprobe cell`; on bridge < 17
      `prid` + `getincell <EditorID>` [not verified]); if not, error `no_anchor`. `anchor_kind = host` makes the fact
      line say «к Хульде», the truth.
   d. error `no_anchor`: «не знаю, куда ставить людей в …».
   A child, a quest ref or an actor CHIM never met is never an anchor. The v1 rule "first named actor of the cell"
   had no order and could pick Амон Мотьер, a disabled quest ref placed in the Bannered Mare (F23).
Other places: `"player"` · `{"npc":"…"}` (an adult) · `{"marker":"court"}` (`tes_watch.court_ref`).

### 6.4 Plan, bind, compile, deferred steps (`dm/compile.php`)

`tesDmCompile(array $plan, callable $resolve, int $bridgeVersion): array` is pure: no DB write, no queue, no game
call. Two things would break that; each has its own place.

**Facts that need the game** (`near`, `here`, `cross`, scan filters, the player's cell). `tesDmBind(array $plan,
TesDmTransport $t, int $bridge): array $world` runs before compile, once per task, only when the plan holds such a
selector: one sequence `tesworld` + `tesscan <tag> near 4000` (+ `tesscan <tag> cross`). `$world` =
`['state' => …, 'scan' => […], 'cross' => …, 'at' => unix]`; it is stored in `tes_watch` key `dm_scan` for 120 s so
that the child gate reads the same flags. `$resolve` is a closure over `$world` and the DB; the compiler never talks
to the game. No answer from the game → the task ends `unverified` before any write.

**Facts that need the run** (`{"step":…}`, `{"spawned":…}`, a step with `if`). The compiler emits a deferred step:
`targets: null`, `defer: {from: "s1", kind: "done"|"spawned"}`, `if: {…}`, no sequences. At compile time it checks
what does not depend on the targets: the op and its parameters, that the named step exists, comes earlier and
produces that kind of set (`spawned` only after `spawn_group` or `create_npc`), the other selectors. At run time the
executor calls `tesDmRenderStep(array $step, array $targets, int $bridgeVersion): array`, the same pure function
the compiler calls for bound steps. Child policy, the dead, the player, shared refs and caps are applied inside
`tesDmRenderStep`, so no step reaches the transport without them.
- `{"step":"s1"}` = the targets of s1 that ended `done`, with the flags s1 resolved. A deferred step cannot widen a set.
- `{"spawned":"s2"}` = the refs `tesspawn` reported, `age: adult`, `source: spawned`: the compiler refused a child
  base, and the bridge deletes a spawned actor for which `IsChild()` is true.
- `"if": {"step":"s1","is":"done"}`: the step runs only when s1 ended with that status (`done`, `partial`, `failed`,
  and for op `check`: `success`, `fail`). A step skipped this way has status `skipped: condition`.

For each step with targets `tesDmRenderStep`: checks the bridge is new enough (else the op's `legacy` template, else
error); removes targets by the op's child policy (§6.5), the player, the dead and shared refs as the op demands and
records them in `skipped`; applies `max_targets` or `max_targets_legacy` (overflow → `over_cap`); renders command
templates, server calls, native calls or a local call. Errors carry `{step, field, error, suggestions}`;
`suggestions` reuse `tesGodGuardSuggestNames`.

Standing rules (§6.10) store the plan unresolved and go through bind and compile at every firing.

### 6.5 Op classes and the child rule: every op

Each catalog entry carries four safety fields; the entry schema rejects an op without them, and a missing value
never means "allowed".

- `child`: `never` = a child or a person of unknown age is never a target, co-target, opponent, source, destination
  or member of a cast of this op: as a target it is skipped and named; as the only answer of `to`, `from`, `vs` or
  `with` it is compile error `child_target`. `benefit` = allowed, and only commands of the CHILD_OK list (§8.8) are
  rendered for a child. `none` = the op has no actor target.
- `harm`: true = autosave before the step, shared refs skipped, `tesfor` at most 5 refs (§6.6), and for more than 3
  targets the wish text must hold a plural or «всех», else the step needs a spoken confirmation.
- `max_targets` / `max_targets_legacy` (bridge < 17).
- `undo` (§6.9) and `confirm`.

Ops with free text that makes someone act (`order`, `director`, `console`, the plan inside `rule_add`): a text that
names a child (lemma step against the child names of `core_npc_master` and of the scan) is refused: `child_named`.
For `console` the compiler also resolves every `{npc:…}`, `{near:…}` and 8-digit ref of the text (selector
`{"ref":…}`, §8.2) and refuses the step the same way when one of them is a child or of unknown age, whatever the
verb.

| Op | Class | Child | Harm | Cap 17 / legacy | Undo | Bridge |
|---|---|---|---|---|---|---|
| `heal` | state | benefit | no | 40 / 15 | skip | s |
| `revive` | state | never | no | 20 / 5 | none | s |
| `kill` | harm | never | yes | 5 / 5 | inverse (resurrect, only the dead) | s |
| `essential` | state | never | yes | 5 / 5 | restore | s |
| `set_skills` | state | never | no | 10 / 5 | restore | s |
| `set_av` | state | never | yes | 10 / 5 | restore | s |
| `add_perks` | state | never | no | 10 / 5 | inverse | s |
| `add_spell` | state | never | no | 10 / 5 | inverse | s |
| `scale` | state | never | yes | 10 / 5 | restore | s |
| `race` | state | never | yes | 5 / – | restore | 17 |
| `idle` | state | never | no | 20 / 10 | skip | s |
| `pacify` | state | never | no | 20 / 10 | restore | s |
| `ally` | state | never | no | 5 / 5 | inverse | s |
| `gather` | move | never, also as `to` | no | 40 / 15 | inverse (`home`) | 17, legacy s |
| `home` | move | never | no | 40 / – | skip | 17 |
| `settle` | move | never | no | 5 / 5 | restore | 17 for `self`, s for `here` |
| `follow` | move | never | no | 3 / 3 | inverse | s |
| `unfollow` | move | never | no | 5 / 5 | inverse | s |
| `jail` | jail | never | yes | 5 / 5 | inverse (`release`) | s |
| `release` | jail | benefit | no | 20 / 10 | none | s |
| `fine` | jail | never | yes | 5 / 5 | own | s (server) |
| `relation` | mind | never | no | 40 | restore | s (server) |
| `remember` | mind | never | no | 40 | own | s (server) |
| `forget` | mind | never | no | 10 | restore | s (server) |
| `character` | mind | never | no | 5 | restore | s (server) |
| `marry` | mind | never, both sides | no | 1 | restore | s (server) |
| `order` | order | never: recipient and text | no | 5 | none | s (server) |
| `director` | order | never: cast and text | no | 12 | none | s (native Director) |
| `give` | give | never, also as `from` | no | 40 / 15 | inverse | s |
| `take` | strip | never, also as `to` | yes | 10 / 5 | inverse | s |
| `take_all` | strip | never | yes | 5 / 5 | inverse (`tesungive`) | s |
| `strip` | strip | never | yes | 20 / 5 | inverse (`tesredress`) | s |
| `dress` | give | benefit | no | 20 / 10 | inverse, not run for a child | s |
| `enchant` | state | never | no | 1 | none | 17 |
| `item_rename` | state | never | no | 1 | restore | 17 |
| `unlock` | world | none | no | 1 | inverse | 17 (crosshair ref) |
| `claim_here` | world | none | no | 1 | none | s |
| `weather` | world | none | no | – | restore | s |
| `hour` | world | none | no | – | restore | s |
| `skip_time` | world | none | no | – | none, confirm | 17 |
| `teleport` | move | never, as `to` and in `with` | no | 5 | none | s |
| `portal` | world | none | no | 1 | own | s (ScriptProxy `SpawnDoor`) |
| `rumor` | world | none | no | – | own | s (server) |
| `document` | world | none | no | – | own | s (server) |
| `title` | world | none | no | – | restore | s (server) |
| `scene_stop` | world | benefit | no | 5 | skip | s (`teslove stop`) |
| `spawn_group` | spawn | never: the base; no hostile group within 1500 units of a child | yes when hostile | 10 | own | 17, legacy s |
| `despawn` | spawn | none | no | – | none | 17 |
| `fight` | combat | never, both sides | yes | 5 per side | inverse (`stop_fight`) | 17 |
| `stop_fight` | world | none | no | – | skip | s |
| `create_npc` | spawn | never: template race | no | 1 | own | s (native) |
| `level` | player | none | no | – | none | s |
| `perk_points` | player | none | no | – | none | s |
| `teach_word` | player | none | no | – | inverse | s (index, F27) |
| `become` | player | none | no | – | none, confirm | 17 |
| `quest_stage` | quest | none | no | – | none, confirm | s |
| `quest_read` | info | none | no | – | skip | s |
| `check` | table | never, as `vs` | no | 1 | skip | s (local, part 9) |
| `plot_open` | table | none | no | – | own | local, part 6 |
| `plot_advance` | table | none | no | – | restore | local, part 6 |
| `plot_close` | table | none | no | – | restore | local, part 6 |
| `fact_set` | table | none | no | – | own | local, part 6 |
| `fact_forget` | table | none | no | – | restore | local, part 6 |
| `rule_add` | table | its plan compiles under these rules at every firing | no | – | own | local, part 6 |
| `rule_remove` | table | none | no | – | restore | local, part 6 |
| `undo` | table | none | no | – | – | local, part 3 |
| `scan` | info | none (children are listed with their flag) | no | – | skip | 17 |
| `answer` | info | none | no | – | skip | s |
| `console` | raw | never: every ref and name in the text; then the guard and the gate | per guard | 8 commands | per `tesAgentInverse` | s |

69 ops. No op starts an intimate scene. Scenes belong to SHARMAT (`ext/aiagent_nsfw`); the code switch is the constant
`TES_WORLD_INSTANT_SCENES`, off (F28). The catalog has no `teslove` template except `scene_stop`; a recipe, a plan, a
rule or raw console text that asks for a scene ends `impossible` with «сцены начинают сами люди»; the child gate
drops every `teslove` that is not `teslove stop` while the constant is off, for adults too.

### 6.6 Executor (`dm/exec.php`)

- Transport interface (§8.9): `send`, `reports`, `server`, `native`, `busy`. Real: `tesWorldQueue` +
  `tes_god_console_log`, `tesGodGuardRunServer`, `herikaInvokeRolemasterCliCommand`. Test: `tools/fake_game.php`. Dry
  runs use the fake. **A test never writes to `skyrim_quest_action_outbox`**: a row left there runs when the game
  starts (one row is pending there today [ran]).
- Every path to the game goes through the transport, and the real transport calls the child gate (§6.8) in each of
  its three write methods. The executor has no other way out: `exec.php`, `verify.php` and `undo.php` contain no
  `insert('skyrim_quest_action_outbox'`, no `tesWorldQueue(`, no `herikaQueueGodCommands(` and no
  `tesGodGuardRunServer(` call outside the transport class (part 3 acceptance greps for them).
- `send` wraps the commands in `tesmark <tag> begin` … `tesmark <tag> end`; only the reports between the two marker
  rows belong to the sequence (fixes F4). On bridge 17 `tesmark … begin` also clears the console selection. On bridge
  15 and 16 an unknown `tes*` command is still logged with its text (`psc:562-569`), so the markers appear. [read; not
  verified in game]
- A sequence holds ≤ 8 commands plus the two markers. Window 1: the next sequence goes out after the previous `end`
  marker or 25 s. Before each send: `busy()`; when busy wait up to 30 s, then stop with `unverified`.
- Bridge 17, bulk: `tesfor`, `tesgather`, `tesprobe` take ≤ 20 refs a command, ≤ 2 such commands a sequence (40 refs,
  about 12 s at the measured 0.29 s per command, F13). A `tesfor` that renders an op with `harm: true` takes ≤ 5 refs,
  one such command a sequence, and the next harmful sequence goes out only after the verify probe showed that the
  previous five hit their own refs; a mismatch stops the step and marks the rest `skipped: halted`. Five, because a
  wrong selection then costs at most five people and `teskill` waits up to 3 s per actor (`psc:284-323`): 15 s, inside
  the 25 s window. The same 5 holds for every inner command outside `TES_BULK_OK` (§8.8): the bridge stops there,
  and the compiler reads the same list, so it never renders a list the bridge would cut. The 30 s lock holds for
  any list length because the bridge restamps it per ref (§8.7).
- Bridge < 17: one target per sequence: `prid <ref>`, ≤ 6 commands. A failed `prid` ends that sequence only (F25).
  The bridge then logs the rest of the sequence as one row with output `error: aborted, target not found`; its command
  text holds `tesmark <tag> end`, and the executor takes that row as the closing marker and marks the target
  `failed: not_found`. About 1.5-2 s a person, hence `max_targets_legacy`.
- Server ops call `transport->server(verb, ref, args)` and re-read the row. Native ops call `transport->native`.
  Local ops (`run.local`) call the handler registered with `tesDmLocalRegister` (part 3 owns the registry and `undo`,
  part 6 registers plots, facts and rules, part 9 registers `check`); an op without a handler is left out of the
  planner's catalog lines and is compile error `op_not_built`.
- The only model-written console text is op `console`; it goes through `tesGodGuardValidate`, then the gate.
- `tesGodAutosaveIfNeeded` before the first step with `harm: true`, class quest, or a spawn of 3+; `tessave <tag>`
  (bridge 17) before ops whose undo is `none` with `confirm`.
- A step with `"await":"arrive"` polls `tesworld` until the cell changes (≤ 40 s) before the next step (row 28).

### 6.7 Verification and the honest report (`dm/verify.php`)

Each op declares a probe and a predicate. After the step's sequences, one `tesprobe` covers all its targets; the
answer carries each ref's own id, so a wrong selection cannot pass as success. On bridge < 17 the probe is the
`prid` + `getdead` / `getdistance` / `tesstate` set `ext/tes_world/verify.php` already uses, inside marker brackets.

Per target: `done` · `failed:<reason>` · `skipped:child|unknown_age|dead|jailed|player|shared_ref|over_cap|condition|
halted` · `unverified` (no answer: pause, menu, loading). Per task: `done` (all done) · `partial` · `failed` ·
`unverified` · `impossible` (compile).

The Narrator receives a server-rendered Russian fact line from the op's `say_ru` templates, for example:
«Собраны 31 из 40 у Хульды в Гарцующей кобыле. Не вышло: Карлотта Валентия (в темнице), Тонаркал (не найден). Детей не
трогал: 7. Не встречал и не трогал: 30.» with the instruction (English) "Tell the player this outcome in your own
voice, 1-2 sentences, Russian, do not claim more than the facts." A task with `unverified` targets says so: «не знаю,
вышло ли».

### 6.8 Safety: one child gate, three layers

Owner, 2026-10-10: children are never harmed, jailed, undressed, robbed, restrained or targeted, by any path.

**The gate** is one file, `ext/tes_world/childsafe.php` (part 0), and every path to the game passes it (§8.8 has the
functions and the verb lists):

| Path | Call site today | After |
|---|---|---|
| `tes_world` sequences | `tesWorldQueue` → `tesChildSafeCommands` (`lib.php:425`) | same call, new rules |
| `tes_crime` sequences | `tesCrimeQueue` (`tes_crime/lib.php:51`) | same call, new rules |
| Narrator console text, loop worker writes | the guard's own verb list (`functions.php:1572-1586`), then the core queue | `tesGodGuardFilterAction` passes the kept text through `tesChildSafeText` |
| `{near:Name}` commands | `tesGodGuardQueueNearby` inserts `tesnear <name>` + body, unfiltered (`functions.php:2146`) | through `tesChildSafeCommands` |
| Server verbs (jail, fine, marry, order, relation, remember, character) | child check for `jail` only (`functions.php:789`) | first line of `tesGodGuardRunServer`: `tesChildSafeServer` |
| Compiled plan: console, server, native | did not exist | the real transport calls the gate in `send`, `server`, `native` |
| Standing rules | did not exist | fire through compile and the transport |

Gate rules:
1. Selection is followed in every form: `prid <1-8 hex>` in any letter case, with or without quotes; `tesnear <name>`
   (exact CHIM name, else unknown); `<ref>.<command>`; `player.<command>`. `tesmark … begin` resets it.
2. Age is `tesChildSafeAge(ref)`: `child` when any source says child (scan flag C of `dm_scan`; **any**
   `core_npc_master` row with that refid, no `LIMIT 1`; the index: placed ref → base → `child`, F27, where a base
   with `tpl` = `lvln` or without a race row says nothing); `adult` when a source says adult
   and none says child; `thing` for a container or door of the scan; else `unknown`.
3. After a child selection only CHILD_OK commands pass (a whitelist: reads, heal, dress, release). `unknown` is
   treated as a child. The old blacklist had holes (F20).
4. Commands that name other refs are checked on them: `startcombat`, `tesduel`, `teslove`, `tesswapworn`, and the ref
   lists of `tesfor`, `tesgather`, `tesfight`. A list is rewritten without child and unknown refs (for `tesfor` they
   stay when the inner command is CHILD_OK); an empty list drops the command. Decimal ids are converted with
   `sprintf('%08X', $n & 0xFFFFFFFF)`.
5. `teslove` with anything but `stop` is dropped for everyone while `TES_WORLD_INSTANT_SCENES` is not true.
6. Every drop is written to the error log as `[childsafe] …` and kept for `tesChildSafeLast()`.

Three layers on top of each other:
1. Compile: child policy of §6.5, `unknown_age` skipped, `child_target`, `child_named`.
2. Gate, at the transport. A drop at the gate for a compiled sequence means the compiler leaked: the task ends
   `failed` with reason `childsafe_leak`, the panel shows it in red, the rest of the plan does not run.
3. Bridge v17: `tesfor`, `tesgather`, `tesfight`, `tesspawn` and the seven hardened commands ask `IsChild()` first
   (§8.7). The engine's flag is the last word and covers children that CHIM and the index do not know.

The planner prompt carries one sentence on children, with no exception list. `worker.php:696` lost the "jailing is
fine" sentence in 780852c (F19).

### 6.9 Undo and the journal (`dm/undo.php`, tables in `dm/schema.php`)

Each op declares one undo kind:

| Kind | Meaning | Examples |
|---|---|---|
| `inverse` | fixed inverse command | give ↔ take, addperk ↔ removeperk, follow ↔ unfollow, gather → `teshome` |
| `restore` | read before, write back | setav (`tesprobe av:`), weather and hour (`tesworld`), relation and character fields (row copy), race, item name |
| `own` | remove what this task made | spawn → `tesdespawn <tag>`, memory and rumour rows by id, document, standing rule, fine row |
| `none` | cannot be undone; named in the report | quest stage, `tesbecome`, an order already spoken |
| `skip` | nothing to undo | heal, idle, reads |

The journal row exists before the command leaves. Order for every step:
1. Pre-probe sequence, read-only, for ops of kind `restore`: the `before` values.
2. One DB transaction: a `tes_dm_steps` row per target with status `planned`, and for kinds `inverse`, `restore`,
   `own` a `tes_agent_undo` row with `state = 'planned'`, the inverse and `before`.
3. `send` / `server` / `native`. Not accepted → the rows become `void` and `failed: not_queued`. Accepted → `sent`.
4. Reports and the verify probe → `done`, `failed:<reason>` or `unverified`; the undo row becomes `verified` on `done`.

A crash between 2 and 3 leaves `planned` rows for a command that never left; a crash between 3 and 4 leaves
`planned` or `sent` rows for a command that may have run. Undo therefore: `verified` rows are undone directly; for
`planned` and `sent` rows it first runs the op's verify probe and undoes only where the effect is present, else
marks the row undone with `not_applied`; no answer → the row stays and the report says `unverified`; `void` rows are
ignored. Server ops that only write the DB do steps 2-4 inside one transaction with the write.

Ops of kind `none` with `confirm` need `tessave` plus a spoken confirmation («точно?») unless the recipe sets
`"confirm": false`; the outcome names the save.

Entry points: op `undo` (`{"scope":"last"}` | `{"scope":"minutes","minutes":5}` | `{"scope":"target","who":…}`),
used by the recipes of rows 57, 58, 60 and by the planner. «Верни как было» keeps its entry point (`tesRealmUndo`,
`realm.php:381`): `tesAgentUndoLast` hands a task whose rows have `step_id <> ''` to `tesDmUndo(['last' => true])`
(part 8). Undo runs through the same executor, gate and verifier, so it reports what did not come back. Undo never
sends a command to a child: `dress` on a child is not reversed. Resurrect stays conditional on fresh `is_dead`
(`lib.php:207-212`).

### 6.10 Standing rules (`dm/campaign.php`, table `tes_dm_rules`)

A rule is `{when, who, condition, plan, cooldown}` evaluated by a tick with zero model calls. It replaces the agent
law patrol (9 of 10 rounds failed; `ext/tes_world/postrequest.php`, the `tes_world_patrols` block from line 107) and covers rows 24, 33, 52.
- `when`: `tick` (every N s), `seen` (a matching person appears in an `infonpc` event), `location` (hold or cell changes).
- `condition`: a selector filter or a probe predicate (`worn body`, `in hold`).
- `plan`: a normal plan, stored unresolved; at each firing it passes bind, compile, the gate and the journal.
- Limits: ≤ 10 active rules, ≤ 1 rule execution per 60 s, ≤ 3 targets per execution, nothing while the queue is busy.

A wish creates a rule with op `rule_add`; «отмени правило …» and undo remove it.

### 6.11 What stays unchanged

`goal:` and `ask:` prefixes; the guard for Narrator console text; `tes_world` spoken handlers and fast orders;
`tes_crime`; the gods; the panel's undo button; the task queue (`tesAgentStart`, one worker at a time).

---

## 7. The tabletop layer (same catalog, same executor)

### 7.1 Hidden checks: owner has not confirmed yet, build last

Owner, 2026-10-10: no dice in combat, Skyrim's engine decides combat; no dice shown to the player. What may stay is
a hidden check for four things done by word outside combat: persuade, bribe, lie, steal by word. The owner has not
confirmed even that. It is part 9, built last, behind `const TES_DM_HIDDEN_CHECKS = false` (`ext/tes_agent/lib.php`).
While the constant is false: op `check` has no handler and is absent from the planner's catalog lines, the `check:`
prefix is not taken, the speech hook does nothing.

Removed from v1 for good: op `roll`, `NdM+K` expressions, d20, natural 1 and 20, the corner notice «d20: 14», the
`*проверка …*` text in the player's line, opposed contests, attribute checks, a lock check. Rows 44, 46, 47 are out of
scope. Lockpicking, pickpocketing by hand and every blow stay with the engine.

| Kind | Player's skill | Spoken stems (data: `recipes.json`, table `checks`) |
|---|---|---|
| persuade | Speechcraft | уговор, уговар, убед, убежд, упрош |
| bribe | Speechcraft, plus the gold offered | взятк, подкуп, заплачу за, золот … за то |
| lie | Speechcraft | совр, обман, притвор, выдам себя, скажу что |
| con (steal by word) | (Speechcraft + Pickpocket) / 2 | выман, выклянч, уговор … отдать, одолж … навсегда |

- `skill`: the player's real base value 0-100 from `00000014.tesstate` (`psc:759-832`, A14), cached in `tes_watch` key
  `dm_sheet` for 120 s. No answer from the game → no check; the dialogue goes on as today. Nothing is rolled blind.
- `aff`: the NPC's affinity to the player, −100…100, the value `.relation` writes through
  `RelationshipManager::setRelationship` (`functions.php:552`).
- `npc`: the NPC's `speech` in `core_npc_master.metadata.skills` (Назим: 9 [ran]); 15 when absent.
- `stake`: 0 small, 15 medium (default), 30 large.
- `chance = clamp(floor(50 + 0.5 × (skill − npc) + 0.25 × aff − stake + bribe), 5, 95)`;
  `bribe = min(25, 25 × gold / (50 × max(1, npc level)))` for kind bribe, else 0.
  Success when `random_int(1, 100) <= chance`. Margin: `clearly` when the draw is 20 or more away from `chance`,
  else `narrowly`.
- Never in combat: no check when `tesworld` says `combat=1`, when the NPC has scan flag K or H, or (bridge < 17) when
  its entry in the last `infonpc` event carries `(hostile)` or `(in combat)`.
- Never against a child: `vs` a child or a person of unknown age → no check (child policy `never`).
- One result per NPC, kind and 10 game minutes; asking again reuses the stored row.
- The player sees no number, no word "check", no skill name. The result is one English line in that NPC's prompt
  (`chimRegisterPromptInjection`): "The player's attempt to persuade you has SUCCEEDED (narrowly). Play it out in your
  own words and actions; never mention a check, a roll, a number or a skill."
- Three entries: the speech hook in `ext/tes_agent/preprocessing.php` (the player's line to an NPC holds a stem of
  the table); the Narrator's target `check: <kind> <npc name> [small|medium|large] | <what is at stake>`, handled
  synchronously in `ext/tes_agent/functions.php`, answer through `tesAgentNarratorSay`; op `check` in a plan
  (`vs`, `args.kind`, `args.stake`), whose later steps use `"if": {"step":"s1","is":"success"}`.
- Every check is a row in `tes_dm_checks`; only the panel shows it.

### 7.2 Encounters (`dm/encounters.json`)

An encounter is data: groups `{who: creature selector, n: [min,max], dist, angle, stance, level}` plus optional
`hostile_to`. Ops `spawn_group` (bridge `tesspawn`) and `fight` (`tesfight`) execute it. No dice: the engine fights
the fight. Count scales with the player's level from the sheet. The spawned refs return in the report, so the
encounter is verified (alive count by `tesprobe life`), journalled as `own`, and removable with one `tesdespawn`.
Caps: ≤ 10 actors per group, ≤ 20 per wish. Children: a base of a child race is compile error `child_target`
(index `child` flag, F27; a base the index cannot classify, `tpl` = `lvln` included, is refused when its name or
EditorID holds `child`, `реб`, `дитя`, `девочк`, `мальчик`); no hostile group within 1500 units of a child the scan shows; on bridge < 17, where there is no scan, a
hostile group is refused when a known child is in the last nearby list.
Rows 39, 40, 56 are three entries of this file plus recipes.

### 7.3 Campaign memory (`dm/campaign.php`, table `tes_dm_campaign`)

Rows of kind `plot` (title, state, next beat, deadline), `fact` (a renamed place, a promise, a debt), `party`
(members), `deed` (what the DM did, written by the executor from the outcome). Ops `plot_open`, `plot_advance`,
`plot_close`, `fact_set`, `fact_forget` write it; no summarising model call exists.

`ext/tes_agent/context_pre.php` injects one line into Narrator requests only, ≤ 600 characters: up to 3 open plots,
active standing rules, the last 3 deeds. It replaces part of today's instruction line (`context_pre.php:15-25`), so
the Narrator prompt does not grow.

A "quest" from a wish (row 36) = `plot_open` + `document` (the task as a letter) + `spawn_group` or `gather` at an
existing place + a standing rule that closes the plot when its condition holds (target dead, item in the player's
inventory, player in the cell). No journal entry: said to the owner as a limit.

Rollback with a save: every new table carries `gamets bigint` and is listed in
`ext/tes_world/playthrough_tables.txt`. Restoring them on load belongs to design P5 (`13-16-npc-state.md`); this
design only supplies the column. The Playthrough Save core patch stays unapplied (commit 23c6cfb).

---

## 8. Contracts

### 8.1 Plan (the planner's only tool, `submit_plan`)

```json
{
  "type": "object",
  "required": ["steps"],
  "properties": {
    "say": {"type": "string", "description": "One short Russian line for the Narrator while work starts. Optional."},
    "impossible": {"type": "string", "description": "Why the wish cannot be done with the catalog. Then steps is []."},
    "explore": {"type": "boolean", "description": "True only when the next action depends on facts no selector gives."},
    "steps": {"type": "array", "maxItems": 12, "items": {
      "type": "object", "required": ["id", "op"],
      "properties": {
        "id": {"type": "string", "pattern": "^s[0-9]{1,2}$"},
        "op": {"type": "string", "description": "An op name from the catalog."},
        "who": {"description": "Actor selector: \"player\", {\"name\":…}, {\"in\":…}, {\"near\":true}, {\"cross\":true}, {\"step\":\"s1\"}, {\"spawned\":\"s1\"}, with filters."},
        "to": {"description": "Place selector (where people go) or actor selector / \"player\" (who gets what op take removes)."},
        "from": {"description": "Actor selector or \"player\": whose things op give hands over. Omit it to create the things."},
        "vs": {"description": "Actor selector: the other side of fight or check."},
        "with": {"description": "Actor selector: who travels with the player in teleport."},
        "args": {"type": "object", "description": "Op parameters as the catalog lists them. Items: [{\"name\"|\"formid\"|\"best\"|\"kit\"|\"match\"|\"gold\": …, \"n\": count}]."},
        "limit": {"type": "integer"},
        "if": {"type": "object", "required": ["step", "is"], "properties": {
          "step": {"type": "string"}, "is": {"enum": ["done", "partial", "failed", "success", "fail"]}}},
        "await": {"type": "string", "enum": ["arrive"]}
      }}}
  }
}
```

Direction and quantity of things:
- `give`: `who` receives. No `from` → the things are created (`additem`). `from: "player"` or an actor → a transfer:
  pre-probe `item:<form>` on the giver, `removeitem` there and `additem` at the receiver in one sequence, count =
  min(asked, held); the fact line says how many moved.
- `take`: `who` loses. No `to` → the things vanish. `to: "player"` or an actor → a transfer the other way.
- `n` sits on every item; `"all"` only for `take`.
- A child is never `who`, `from` or `to` of `give`, `take`, `take_all`.

Examples:
```json
{"say":"Созываю Вайтран.","steps":[
 {"id":"s1","op":"gather","who":{"in":"Вайтран","role_not":["guard"]},"to":{"place":"Гарцующая кобыла"}}]}
```
```json
{"steps":[
 {"id":"s1","op":"heal","who":{"name":"Лидия"}},
 {"id":"s2","op":"give","who":{"name":"Лидия"},"args":{"items":[{"kit":"dragon_fighter"}],"equip":true}},
 {"id":"s3","op":"set_skills","who":{"name":"Лидия"},"args":{"values":{"TwoHanded":100,"HeavyArmor":100,"Block":100},"raise_only":true}}]}
```
Row 9: `{"id":"s1","op":"give","who":{"in":"here"},"args":{"items":[{"name":"мёд","n":1}]}}`.
Task 165 («всё мясо мне отдай»): `{"id":"s1","op":"take","who":{"near":true},"to":"player","args":{"items":[{"match":"мясо","type":"food","n":"all"}]}}`.
Row 5: `{"id":"s1","op":"revive","who":{"near":true,"alive":false}}`. Row 25: `{"id":"s1","op":"heal","who":{"near":true,"wounded":true}}`.
Row 43: `{"id":"s1","op":"pacify","who":{"cross":true,"person":false}}`, `{"id":"s2","op":"ally","who":{"step":"s1"}}`.
Row 56: `{"id":"s1","op":"spawn_group","args":{"encounter":"caravan"}}`, `{"id":"s2","op":"spawn_group","args":{"encounter":"bandits","n":5}}`,
`{"id":"s3","op":"fight","who":{"spawned":"s2"},"vs":{"spawned":"s1"},"if":{"step":"s2","is":"done"}}`.
Row 57: `{"id":"s1","op":"undo","args":{"scope":"last"}}`.

### 8.2 Shapes that cross part borders

Target (part 2 produces, parts 1 and 3 consume):
```json
{"ref":"0001A66E","name":"Хульда","age":"adult","dead":false,"jailed":false,"sex":"F","known":true,"shared":false,
 "kind":"actor","source":"chim","hp":100,"dist":420,"flags":"FU"}
```
`ref`: 8 upper-case hex digits or `"player"`. `age`: `adult` | `child` | `unknown`. `kind`: `actor` | `container` |
`door`. `source`: `chim` | `index` | `scan` | `spawned` | `step`. `hp`, `dist`, `flags` exist only for scan targets.

Resolver (part 2 produces, part 1 calls): `$resolve(string $kind, mixed $selector): array`.
- `actors` → `['targets' => [target,…], 'skipped' => ['unknown_sex' => n, …], 'outside' => n]`; besides the
  sources of §6.3 it takes `{"ref":"0001A66E"}`, used by the compiler for op `console` and by undo. The planner
  never writes a ref.
- `place` → `['cell' => EditorID, 'anchor' => ref|'player', 'anchor_kind' => 'static'|'player'|'host', 'name' => …]`
- `items` → `[['formid' => …, 'name' => …, 'n' => int|'all'], …]`
- `local` (selector = op name) → `true` when a handler is registered
- any of them → `['error' => code, 'suggestions' => […]]`

World snapshot (part 3 `tesDmBind` produces, part 2 consumes): `['state' => ['hour','days','weather','cell',
'interior','combat','level','loc'], 'scan' => [target,…]|null, 'cross' => target|null, 'at' => unix]`; `null` = no
snapshot was needed.

Compiled plan (part 1 produces, part 3 consumes): `['steps' => [step,…], 'errors' => [['step','field','error',
'suggestions'],…]]`; a plan with errors has no sequences. Step:
`['id','op','class','child','harm','confirm','targets' => [target,…]|null,'skipped' => [['ref','name','why'],…],
'defer' => null|['from' => id,'kind' => 'done'|'spawned'],'if' => null|['step','is'],'pre' => [cmd,…],
'sequences' => [[cmd,…],…],'server' => [['verb','ref','args'],…],'native' => [['kind','args'],…],
'local' => null|['op','args'],'probe' => […],'undo' => […]]`.

Error codes: `unknown_op`, `op_not_built`, `bridge_too_old`, `missing_param`, `bad_param`, `unknown`, `ambiguous`,
`needs_index`, `filter_needs_scan`, `no_anchor`, `child_target`, `child_named`, `bad_step_ref`, `scene_refused`.

Outcome (part 3 produces; stored in `tes_agent_tasks.outcome`): `['status' => done|partial|failed|unverified|impossible,
'leak' => bool, 'steps' => [['id','op','status','ok' => n,'n' => n,'failed' => [['name','why'],…],
'skipped' => [why => n,…],'outside' => n],…], 'say_ru' => string]`.

### 8.3 Planner system prompt (English, exact)

```
You plan one wish of the player for the game master of a Skyrim SE playthrough (Requiem/RFAD, Russian game data).
Answer with exactly one submit_plan call.
- Use only ops from the catalog below. One step can act on a whole set of people: use a selector, never list people one by one.
- You never write IDs. Name people, places and things in Russian, in the dictionary form (nominative); when Facts lists the name, copy it from there. The server finds them.
- Do exactly what was asked, nothing more. Move the player only when he asked to be moved.
- Children are never the target of anything except heal, dress and release. Do not plan around this; the server leaves them out.
- You never start an intimate scene, never roll dice and never announce a check. If the wish asks for that, set "impossible".
- Things have a direction: give = "who" receives and "from" is the giver (omit it to create the things); take = "who" loses and "to" receives. Put the count in "n".
- Sets seen in the game (the wounded, the dead, the hostile, what the player looks at) need the selectors near, here or cross.
- A later step may use the result of an earlier one: {"step":"s1"}, {"spawned":"s1"}, or "if": {"step":"s1","is":"done"}.
- "Best" gear: use {"best":{...}} or a kit; do not search.
- To take something back use op "undo".
- A question about the world: one step with op "answer" and the selectors that hold the facts.
- If the catalog cannot do the wish, set "impossible" to the reason and steps to [].
- Every text the player hears or reads (say, documents, rumours, memories) is Russian.
Catalog: <one line per op: name, params, one-sentence description>
Facts: <perception pack>
```
Repair turn, user message: `Your plan did not compile. Fix only these errors and submit again: <errors JSON>`.

### 8.4 Catalog entry (`ext/tes_agent/dm/catalog.json`)

```json
{"op": "gather",
 "desc": "Bring people to a place or to the player.",
 "params": {"who": "actors", "to": "place"},
 "class": "move", "child": "never", "harm": false, "confirm": false,
 "max_targets": 40, "max_targets_legacy": 15, "min_bridge": 17,
 "run":    {"bulk": "tesgather {tag} {to.dec} 300 {refs.dec}"},
 "legacy": {"each": ["moveto {to.hex_or_player}"]},
 "pre":    null,
 "verify": {"probe": "near:{to.dec}", "ok": "0 <= v && v <= 2500", "delay_s": 3},
 "undo":   {"kind": "inverse", "bulk": "tesfor {tag} {refs.dec} :: teshome"},
 "say_ru": {"done": "Собраны {ok}: {to.say}.", "partial": "Собраны {ok} из {n}: {to.say}.", "failed": "Собрать не вышло."},
 "example": {"id": "s1", "op": "gather", "who": {"in": "Вайтран"}, "to": {"place": "Гарцующая кобыла"}}}
```
Required in every entry: `op`, `desc`, `params`, `class`, `child`, `harm`, `confirm`, `max_targets`, `min_bridge`,
`run`, `verify`, `undo`, `say_ru`, `example` (`max_targets` is null where §6.5 shows –; `verify` is null only
for undo kind `skip`). `example` is one valid step; the tests of parts 1, 3 and 8 are driven by
it, so an op cannot enter the catalog untested. The values of `class`, `child`, `harm`, the caps, `undo` and
`min_bridge` are the table of §6.5; part 1's test compares the file with that table.

`run` is exactly one of: `bulk` (one command for many refs) · `each` (per target: rendered into `tesfor` when every
line is a plain console command and the bridge is ≥ 17, else one target per sequence after the executor's own
`prid`) · `server` (a `tesGodGuardRunServer` verb) · `native` (`director`, `create_npc`, `spawn_template`, `portal`) ·
`local` (a registered PHP handler). `legacy.each` holds the commands that follow the executor's `prid <ref>`.
`pre` is the probe that reads `before` for undo kind `restore`. `verify.ok` is evaluated by a 20-line expression
checker over `v` (number or letter); no `eval`.

The first catalog is the 69 ops of §6.5, in these groups for the prompt lines:

| Group | Ops |
|---|---|
| people, state | `heal` `revive` `kill` `essential` `set_skills` `set_av` `add_perks` `add_spell` `scale` `race` `idle` `pacify` `ally` |
| people, place | `gather` `home` `settle` `follow` `unfollow` `jail` `release` `fine` |
| people, mind | `relation` `remember` `forget` `character` `marry` `order` `director` |
| things | `give` `take` `take_all` `strip` `dress` `enchant` `item_rename` `unlock` `claim_here` |
| world | `weather` `hour` `skip_time` `teleport` `portal` `rumor` `document` `title` `scene_stop` |
| creatures | `spawn_group` `despawn` `fight` `stop_fight` `create_npc` |
| player | `level` `perk_points` `teach_word` `become` |
| quests | `quest_stage` `quest_read` |
| table | `check` `plot_open` `plot_advance` `plot_close` `fact_set` `fact_forget` `rule_add` `rule_remove` `undo` |
| info | `scan` `answer` `console` |

Against v1: `roll` removed (owner: no dice shown); `scene` renamed `director` (it is the native Director's spoken
scene, never an intimate one); `scene_stop` and `undo` added; `unlock` moved to bridge 17 (it needs the crosshair ref).

### 8.5 Recipes (`ext/tes_agent/dm/recipes.json`)

A recipe is a set of features, not one regex with a fixed word order. The goal reaches the worker in the Narrator's
words, in any order and form (F24).

```json
{"id": "gather",
 "verbs": ["собер", "собр", "собир", "созов", "созва", "созыв", "сгон", "согна", "согон"],
 "alt":   ["(?<![\\p{L}])появ\\p{L}*(?:\\s+\\p{L}+){0,2}?\\s+(?:рядом|сюда|здесь|тут|ко\\s+мне|около\\s+меня)"],
 "veto":  ["\\?\\s*$",
           "(?<![\\p{L}])(?:не|никто|никого|чего|почему|зачем)\\s+(?:\\p{L}+\\s+){0,2}?{verb}",
           "{verb}(?<=лся|лись|лась)(?![\\p{L}])"],
 "slots": {"who":  {"type": "people_word", "required": true},
           "city": {"type": "city", "default": "hold"},
           "to":   {"type": "venue", "default": "player"}},
 "pre": false, "confirm": false,
 "plan": {"steps": [{"id": "s1", "op": "gather", "who": {"in": "{city}", "$people": "{who}"}, "to": "{to}"}]}}
```

`tesDmRecipe(string $goal, string $said = ''): ?array` (part 5):
1. Normalise: lower case, ё → е, the `*…*` and `(…)` service parts and the speaker prefix removed.
2. A recipe fires when a verb stem (as `(?<![\p{L}])<stem>\p{L}*`) or an `alt` regex matches and no `veto` matches;
   `{verb}` in a veto stands for the verb alternation.
3. Slots are filled by typed extractors over the whole text, in any word order: `people_word` (table: жител, люд,
   народ, горожан, всех → no filter; женщин, баб → female; мужчин, мужик → male; страж → role guard), `city` and `venue`
   (lemma step with pass 3 on the cities and on the places of the current hold; a venue is a named interior, a city
   has a `<Base>World`), `npc` (lemma step), `number` (`tesWorldSpokenAmount`, `lib.php:548`), `craft_word`, `kit_word`.
4. It abstains (returns null, the planner takes the wish) when two recipes fire, when a required slot stays empty,
   or when the text holds a stem of the table `other_deeds` (выда, разде, убей, убь, арест, посад, оштраф, наден, одень,
   накорм, …) outside the recipe's own verbs: a second deed in one sentence is a plan, not a recipe.
5. The goal is tried first, then `said` (the player's own line, stored by part 8 in `tes_agent_tasks.said`); two
   different recipes → null.
6. A recipe whose plan does not compile falls through to the planner.

A slot value replaces its whole quoted placeholder and may be an object: `"{to}"` becomes `"player"` or
`{"place":"Гарцующая кобыла"}`; `"$people": "{who}"` is replaced by the filter keys of the people word.

[ran, PHP prototype of rules 1-3 on live text, read-only] On the 19 distinct plain goals of `tes_agent_tasks` the
`gather` recipe fires on exactly three, the three real gather goals:
185 «собрать всех жителей Вайтрана в Гарцующей кобыле» → `{"in":"Вайтран"}` to «Гарцующая кобыла»;
168 «собрать женщин в Гарцующей кобыле и ждать…» → women of the hold to «Гарцующая кобыла»;
197 «Шаман хочет, чтобы все люди в Вайтране появились рядом с ним» → `{"in":"Вайтран"}` to the player.
On the 251 lines spoken to the Narrator it fires on 8, among them «Собрать всех женщин в горчующие кобыли» and «Собери
всех людей в Айтране на рынке» (venue and city found by pass 3), and stays silent on «Но никто не собрался», «Ну чего
ты собираешь всех?», «Чего ты всех как-то не собираешь». One of the 8 also orders armour handed out; rule 4 sends it
to the planner. Rule 4 is not prototyped.

First set: 20 recipes covering rows 5, 9, 11, 18, 21-23, 25, 26, 31, 39, 40, 49, 52, 57, 58, 60 and three more of the
«бог <ремесла>» family. The table `checks` of §7.1 lives in the same file and is read by part 9 only.

### 8.6 Tables (`ext/tes_agent/dm/schema.php`, part 3)

All SQL of the DM code names its tables through `tesDmTable('steps')`, which returns `<schema>.tes_dm_steps`;
`tesDmSchema()` returns `$GLOBALS['TES_DM_SCHEMA'] ?? 'public'`. Tests set a scratch schema
`tes_dm_scratch_<pid>`: `CREATE SCHEMA`, run, `DROP SCHEMA … CASCADE` in a shutdown function; at start a test drops
any leftover `tes_dm_scratch_%` schema. Role `dwemer` may create schemas in `dwemer` [ran]. Temp tables are not
used: existing code names `public.tes_agent_undo` with the prefix (`lib.php:164, 179`), which a temp table does not
shadow. `tesDmEnsureTables()` runs the statements below; `<s>` is the schema.

```sql
-- existing table, new columns
ALTER TABLE <s>.tes_agent_tasks ADD COLUMN IF NOT EXISTS mode text NOT NULL DEFAULT 'loop';   -- recipe | plan | loop
ALTER TABLE <s>.tes_agent_tasks ADD COLUMN IF NOT EXISTS turns int NOT NULL DEFAULT 0;        -- model turns, not calls
ALTER TABLE <s>.tes_agent_tasks ADD COLUMN IF NOT EXISTS said text NOT NULL DEFAULT '';       -- the player's own line
ALTER TABLE <s>.tes_agent_tasks ADD COLUMN IF NOT EXISTS plan jsonb;
ALTER TABLE <s>.tes_agent_tasks ADD COLUMN IF NOT EXISTS outcome jsonb;                       -- §8.2
ALTER TABLE <s>.tes_agent_tasks ADD COLUMN IF NOT EXISTS gamets bigint;

-- tes_agent_undo: first the old shape, word for word as ext/tes_agent/lib.php:164 and
-- tools/ensure_playthrough_tables.php:24 create it, then the new columns. Whoever runs first, the result is the same.
CREATE TABLE IF NOT EXISTS <s>.tes_agent_undo (id bigserial PRIMARY KEY, task_id bigint NOT NULL, command text NOT NULL,
  inverse text, undone boolean NOT NULL DEFAULT false, created_at timestamptz NOT NULL DEFAULT now());
ALTER TABLE <s>.tes_agent_undo ADD COLUMN IF NOT EXISTS step_id text NOT NULL DEFAULT '';
ALTER TABLE <s>.tes_agent_undo ADD COLUMN IF NOT EXISTS target_ref text NOT NULL DEFAULT '';
ALTER TABLE <s>.tes_agent_undo ADD COLUMN IF NOT EXISTS kind text NOT NULL DEFAULT 'inverse';  -- inverse | restore | own
ALTER TABLE <s>.tes_agent_undo ADD COLUMN IF NOT EXISTS before jsonb;
ALTER TABLE <s>.tes_agent_undo ADD COLUMN IF NOT EXISTS state text NOT NULL DEFAULT 'sent';    -- planned | sent | verified | void
ALTER TABLE <s>.tes_agent_undo ADD COLUMN IF NOT EXISTS detail text NOT NULL DEFAULT '';
-- default 'sent': rows of the old tesAgentJournal are written after the queue took the command.

CREATE TABLE IF NOT EXISTS <s>.tes_dm_steps (
  id bigserial PRIMARY KEY, task_id bigint NOT NULL, step_id text NOT NULL, op text NOT NULL,
  target_ref text NOT NULL DEFAULT '', target_name text NOT NULL DEFAULT '',
  status text NOT NULL,            -- planned | sent | done | failed | skipped | unverified
  detail text NOT NULL DEFAULT '', before jsonb, tag text NOT NULL DEFAULT '',
  created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), gamets bigint);

CREATE TABLE IF NOT EXISTS <s>.tes_dm_checks (            -- part 9
  id bigserial PRIMARY KEY, created_at timestamptz NOT NULL DEFAULT now(), gamets bigint, task_id bigint,
  kind text NOT NULL,              -- persuade | bribe | lie | con
  npc text NOT NULL, npc_ref text NOT NULL DEFAULT '', skill int NOT NULL, npc_skill int NOT NULL, affinity int NOT NULL,
  stake int NOT NULL DEFAULT 15, gold int NOT NULL DEFAULT 0, chance int NOT NULL, draw int NOT NULL,
  outcome text NOT NULL,           -- success | fail
  margin text NOT NULL DEFAULT '', -- clearly | narrowly
  what text NOT NULL DEFAULT '');

CREATE TABLE IF NOT EXISTS <s>.tes_dm_campaign (
  id bigserial PRIMARY KEY, kind text NOT NULL,        -- plot | fact | party | deed
  title text NOT NULL, body text NOT NULL DEFAULT '', state text NOT NULL DEFAULT 'open',
  data jsonb NOT NULL DEFAULT '{}', task_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), gamets bigint);

CREATE TABLE IF NOT EXISTS <s>.tes_dm_rules (
  id bigserial PRIMARY KEY, title text NOT NULL, when_kind text NOT NULL,   -- tick | seen | location
  who jsonb NOT NULL, condition jsonb NOT NULL DEFAULT '{}', plan jsonb NOT NULL,
  cooldown_s int NOT NULL DEFAULT 600, active boolean NOT NULL DEFAULT true,
  last_run timestamptz, runs int NOT NULL DEFAULT 0, task_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(), gamets bigint);
```
`tes_agent_tasks` and `tes_agent_undo` are already in `ext/tes_world/playthrough_tables.txt` [read]; part 8 adds the
four `tes_dm_*` names. In `public` the tables appear on the first real run, as every `tes_*` table does. Nothing of
this exists in the live DB today [ran].

### 8.7 Bridge v17: the exact list

One recompile, one game restart. FormIDs in arguments are **signed 32-bit decimal** (as `tesduel` and `teslove` take
them); lists are comma-separated without spaces, ≤ 20 refs. Reports are `command@@output` through
`AIAgentFunctions.logMessage(…, "tes_god_console")`, each ≤ 450 characters; longer answers are split into chunks
`<i>/<n>`.

| # | Command | Does | Report output |
|---|---|---|---|
| 1 | `tesversion` | | `17` |
| 2 | `tesmark <tag> begin` / `end` | brackets a sequence; `begin` clears the console selection | `ok` |
| 3 | `tesfor <tag> <refs> :: <command>` | for each ref: restamp the lock, `Game.GetForm`, skip a missing or disabled ref, `ConsoleUtil.SetSelectedReference`, run `<command>` (a `tes*` command through the bridge dispatcher, anything else through `ConsoleUtil.ExecuteCommand`). For an `IsChild()` actor only CHILD_OK commands run. When `<command>` is not BULK_OK only the first 5 refs run. A `teslove` command never runs. No `prid`. | `ok=<n> missing=<refs> child=<refs> off=<refs> over=<n>` |
| 4 | `tesprobe <tag> <what> <refs>` | reads one fact per ref, restamping the lock per ref. `what`: `life` (a alive, d dead, x disabled, m missing) · `near:<ref>` (distance, −1 other cell) · `cell` (FormID of the ref's cell) · `worn` (bit mask head 1, body 2, hands 4, feet 8) · `item:<form>` · `gold` · `av:<Name>` (base) · `fac:<form>` (rank, −2 none) · `flags` | chunks `<ref>=<value>;…` |
| 5 | `tesscan <tag> near <radius>` · `cell` · `cross` · `cont <radius>` | lists actors (or the crosshair ref, or containers and doors). Flags: M F sex, C child, D dead, G guard, H hostile to the player, K in combat, T teammate, E essential, U unique, A not a person, X container, O door | chunks `<ref>:<flags>:<hp%>:<dist>:<name>\|…`, then `tesscan <tag> end@@count=<N>` |
| 6 | `tesgather <tag> <dest ref or 0 = player> <radius> <refs>` | per ref: restamp the lock; skip the missing, the dead, the disabled and every `IsChild()` actor; `MoveTo` on a ring of `<radius>` around the destination, `EvaluatePackage`. Refused as a whole when the destination is an `IsChild()` actor. | `ok=<n> missing=<refs> dead=<refs> off=<refs> child=<refs>` |
| 7 | `teshome` (selected) | `MoveToMyEditorLocation`, removes the routine override, `EvaluatePackage` | `<name> sent home` |
| 8 | `tesroutine self` (new mode of an existing command) | the routine marker goes where the selected actor stands | as `tesroutine here` |
| 9 | `tesspawn <tag> <base> <count ≤ 10> <dist> <angle> <stance>` | `PlaceActorAtMe` at the player, moved `<dist>` units at `<angle>` from his heading. Stance 0 neutral, 1 hostile to the player, 2 ally, 3 calm. A placed actor for which `IsChild()` is true is deleted at once. Refs stored under the tag. | `refs=<refs> child=<n>` |
| 10 | `tesdespawn <tag>` | disables and deletes only refs stored under the tag | `removed=<n>` |
| 11 | `tesfight <tag> <refsA> \| <refsB>` (`14` = the player) | `A[i].StartCombat(B[i mod nB])` and back; `IsChild()` refs dropped from both lists; nothing happens when a side is empty | `pairs=<n> child=<refs> missing=<refs>` |
| 12 | `testime <hours ≤ 720>` | advances game time | `days=<GameDaysPassed> hour=<GameHour>` |
| 13 | `tesworld` | world state in one line | `hour=<f> days=<f> weather=<form> cell=<form> interior=<0\|1> combat=<0\|1> level=<n> loc=<name>` |
| 14 | `tesrace <race form or 0>` (selected, never a child) | `SetRace`; the first original race is stored; 0 restores it | `<name>: <old> -> <new>` |
| 15 | `tesitem <hand: 0 left, 1 right> <temper or 0> <new name or ->` (selected actor) | `WornObject.SetDisplayName`, `SetItemHealthPercent`; old values stored | `<old name> -> <new name> temper <f>` |
| 16 | `tesenchant <hand> <charge> <mgef>:<mag>:<dur>[,…]` (selected actor) | `WornObject.CreateEnchantment` | `<item>: enchanted <n>` or `error: …` |
| 17 | `tesbecome vampire` · `werewolf` · `cure` | calls the vanilla quest scripts on the player | `done` or `error: …` |
| 18 | `tessave <tag>` | `Game.SaveGame("TES_before_" + tag)` | `TES_before_<tag>` |

Changes to existing code in the same version:
- **Lock.** Today the holder stamps `TESConsoleLockAt` once per command of a sequence (`psc:117`) and another row
  force-takes the lock when the stamp is 30 s old (`psc:80-91`). `tesfor`, `tesgather`, `tesprobe`, `tesscan`,
  `tesfight` and `tesspawn` write the stamp before every ref, so one long command never looks dead. The longest gap
  between stamps is one ref's own command: `teskill` waits at most 3 s (`psc:284-323`).
- **A failed `prid` no longer ends the sequence.** `ExecuteConsoleCommandSequence` (`psc:109-133`) returns at the
  first `prid` that is not found and logs the rest as one row. In v17 it skips forward instead: every command up to
  the next `prid`, `tesnear`, `tesmark`, `tesfor`, `tesgather`, `tesprobe`, `tesscan`, `tesfight`, `tesspawn`,
  `tesdespawn`, `tesworld`, `tesversion`, `tessave` or `player.` command is logged as
  `<command>@@error: skipped, target not found` and not run. No command lands on an old selection, and the closing
  `tesmark` always arrives.
- **Hardening.** `teskill`, `tesgive`, `tesjailbox in`, `tesswapworn` (either side), `tesduel` (the condemned),
  `tesessential 0` and `teshold <not 0>` refuse an `IsChild()` actor at their first line and report
  `error: refused (a child)`. Today `teskill` runs `TESMortal` and `Kill()` before its child branch (F7).
- **Verb lists.** CHILD_OK and BULK_OK of §8.8 exist twice: as PHP constants in `childsafe.php` and as Papyrus string
  arrays in the bridge. `tools/test_bridge_contract.php` compares the two.

`ext/tes_agent/dm/bridge.php` holds the command builders and the report parsers; the fake game and the real bridge
both follow this table.

### 8.8 The child gate (`ext/tes_world/childsafe.php`, part 0)

```php
function tesChildSafeAge(string $ref): string;            // 'child' | 'adult' | 'thing' | 'unknown'  (§6.8 rule 2)
function tesChildSafeIsChildRef(string $ref): bool;       // kept for its 10 callers: tesChildSafeAge($ref) === 'child'
function tesChildSafeCommands(array $commands): array;    // one console sequence → the same without what must not run
function tesChildSafeText(string $kept): string;          // guard text "<ref>.cmd; player.cmd; cmd" → the same, filtered
function tesChildSafeServer(string $verb, array $refs, string $text = ''): ?string;   // null = allowed, else a Russian reason
function tesChildSafeNames(): array;                      // names of known children: CHIM rows plus the scan
function tesChildSafeLast(): array;                       // what the last call dropped: [['cmd' => …, 'ref' => …, 'why' => …], …]
const TES_CHILD_OK = [...];
const TES_BULK_OK  = [...];
```

- `TES_CHILD_OK`, the only commands that pass for a selected child or a selection of unknown age:
  reads `get*`, `tesstate`, `tesinspect`; heal `tesheal`, `restoreav`, `resethealth`; dress `equipitem`, `tesdress`,
  `tesdressbest`, `tesredress`; release `tesjailbox out`, `setrestrained 0`, `teshold 0`, `tesfollow 0`, `tesunfollow`,
  `stopcombat`, `resetai`, `evp`, `teshome`, `tesroutine off`, `setav speedmult 100`, `teslove stop`.
- Selection-free commands pass whatever is selected: `player.*`, `tesmark`, `tesversion`, `tesworld`, `tesscan`,
  `tesprobe`, `tesspawn`, `tesdespawn`, `tessave`, `testime`, and the existing `tesrussify`, `tesautosave`, `tespeace`,
  `tesperkpoints`; `tesfor`, `tesgather`, `tesfight` pass with their ref lists rewritten (§6.8 rule 4).
- `TES_BULK_OK`, the inner commands `tesfor` runs for up to 20 refs; anything else stops after 5 refs:
  `TES_CHILD_OK` plus `additem`, `addspell`, `addperk`, `playidle`, `tesfollow`, `setrelationshiprank`, `tesroutine`,
  `resurrect`.
- `tesChildSafeServer`: with a child or unknown-age ref among `$refs` only verb `unjail` is allowed. For `order`,
  `director` and a rule's plan, `$text` is matched against `tesChildSafeNames()` with `tesLemmaMatch`; a hit is the
  reason «в приказе назван ребёнок».
- Before any selection in a sequence the gate passes commands as today: world and player commands (`fw`, `set
  gamehour`, `coc`, `tespeace`, `tesautosave`) open many existing sequences. The compiler never renders a
  selection-bound command without its selection, and `tesmark … begin` clears the selection on bridge 17.

### 8.9 Function signatures, by owner

```php
// part 0  ext/tes_world/lemma.php
function tesLemmaStems(string $word): array;
function tesLemmaMatch(string $spoken, array $candidates, array $opts = []): array;   // ['hits' => […], 'pass' => int]
// part 1  ext/tes_agent/dm/compile.php, dm/bridge.php
function tesDmCatalog(): array;
function tesDmCatalogLines(?callable $has = null): string;        // English prompt lines; $has(op) hides unbuilt local ops
function tesDmCompile(array $plan, callable $resolve, int $bridgeVersion): array;
function tesDmRenderStep(array $step, array $targets, int $bridgeVersion): array;
function tesDmCmd(string $name, array $args): string;             // builder for each row of §8.7
function tesDmParse(string $command, string $output): array;      // parser for each report of §8.7
function tesDmDec(string $hexRef): string;  function tesDmHex(string $dec): string;
// part 2  dm/select.php, dm/perception.php
function tesDmResolver(?array $world): callable;                  // the $resolve of §8.2
function tesDmPerception(string $goal, string $said = ''): array;
// part 3  dm/schema.php, dm/exec.php, dm/verify.php, dm/undo.php
function tesDmSchema(): string;  function tesDmTable(string $short): string;  function tesDmEnsureTables(): void;
interface TesDmTransport {
    public function send(array $commands, string $tag): bool;               // adds the markers; false = not queued
    public function reports(string $tag, float $timeoutS): ?array;          // rows between the markers; null = no end marker
    public function server(string $verb, string $ref, string $args): array; // [bool ok, string text]
    public function native(string $kind, array $args): array;               // [bool ok, string text]
    public function busy(): bool;
}
class TesDmGameTransport implements TesDmTransport {}             // the only code that reaches the game; calls the gate
function tesDmBind(array $plan, TesDmTransport $t, int $bridgeVersion): array;
function tesDmRun(int $taskId, array $compiled, TesDmTransport $t, int $bridgeVersion): array;   // outcome of §8.2
function tesDmUndo(array $filter, TesDmTransport $t): array;      // ['last' => true] | ['minutes' => N] | ['target' => ref]
function tesDmLocalRegister(string $op, callable $fn): void;  function tesDmLocalHas(string $op): bool;
function tesDmSayRu(array $outcome): string;
// part 5  dm/planner.php
function tesDmRecipe(string $goal, string $said = ''): ?array;
function tesDmPlan(string $goal, array $perception, callable $llm): array;
// part 6  dm/campaign.php
function tesDmCampaignLine(): string;  function tesDmRulesDue(array $event): array;
// part 8  ext/tes_agent/lib.php
function tesAgentStart(string $goal, bool $dry = false, bool $readonly = false, bool $quick = false, bool $silent = false, string $said = ''): array;
// part 9  dm/checks.php
function tesDmCheckKind(string $line): ?string;
function tesDmSheet(?string $tesstateLine = null): ?array;
function tesDmCheck(array $spec, array $sheet, ?callable $rng = null): array;
```
A local handler has the form `fn(array $step, array $ctx): array` and returns `['status' => …, 'detail' => …,
'undo' => null|[…]]`.

---

## 9. Parts for builders

The translation worktree is merged (fd68cb1, PLAN row P3-done), so the v1 rule "new files only" is gone. Each part
below names the existing files it may edit; no other existing file is touched, and no two parts that can run at the
same time edit the same file. No part calls a model in its acceptance check. No part writes to
`skyrim_quest_action_outbox` in a test. Tests that need tables use the scratch schema of §8.6.

Order: 0 → 1 → 2 → 3 → 5 → 6 → 8 → 9. Part 4 starts after 0 and 1. Part 7 has no code dependency.

| Part | Existing files it may edit | New files |
|---|---|---|
| 0 child gate, lemma | `ext/tes_world/childsafe.php`; `ext/tes_god_guard/functions.php` (three call sites: `tesGodGuardRunServer` :729, `tesGodGuardQueueNearby` :2141, `tesGodGuardFilterAction` :2241); `ext/tes_crime/lib.php` (`tesCrimeIsChildRef` :34); `tools/test_court.php` (childsafe block) | `ext/tes_world/lemma.php`, `tools/test_childsafe.php` |
| 1 catalog, compiler | none | `ext/tes_agent/dm/catalog.json`, `dm/compile.php`, `dm/bridge.php`, `tools/test_dm_compile.php`, `tools/fixtures/dm_compile/*` |
| 2 selectors, perception | none | `dm/select.php`, `dm/perception.php`, `tools/test_dm_select.php`, `tools/fixtures/dm_select/*` |
| 3 executor, verify, undo | none | `dm/schema.php`, `dm/exec.php`, `dm/verify.php`, `dm/undo.php`, `tools/fake_game.php`, `tools/test_dm_exec.php` |
| 4 bridge v17 | `papyrus/TESGodConsoleReport/Source/Scripts/AIAgentQuestProgressionBridge.psc`, `papyrus/TESGodConsoleReport/README.md`, `docs/in-game-tests.md` | `tools/test_bridge_contract.php` |
| 5 planner, recipes | none | `dm/planner.php`, `dm/recipes.json`, `dm/kits.json`, `tools/test_dm_plan.php`, `tools/fixtures/dm_plan/*` |
| 6 campaign, rules, encounters | none | `dm/campaign.php`, `dm/encounters.json`, `tools/test_dm_table.php` |
| 7 index | `tools/game_index.py`, `tools/test_index_enrich.py` | none |
| 8 integration | `ext/tes_agent/worker.php`, `lib.php`, `functions.php`, `context_pre.php`, `manifest.json`; `ext/tes_world/realm.php`, `postrequest.php`, `panel.php`, `playthrough_tables.txt`; `tools/ensure_playthrough_tables.php`, `tools/deploy_tes_agent.sh`, `tools/test_ext.php` | `ext/tes_agent/preprocessing.php`, `tools/test_dm_bench.php`, `tools/fixtures/dm_bench/01.json … 60.json` |
| 9 hidden checks | `ext/tes_agent/lib.php` (the constant), `functions.php` (`check:`), `preprocessing.php` (the hook), `dm/recipes.json` (table `checks`) | `dm/checks.php`, `tools/test_dm_checks.php` |

### Part 0: the child gate and the lemma step
- Produces: the functions and lists of §8.8; `tesLemmaStems`, `tesLemmaMatch` (§6.2). Existing callers of
  `tesChildSafeCommands` and `tesChildSafeIsChildRef` keep working without a change at their call sites.
- Contract: §6.8 gate rules 1-6; §8.8. SELECT only. Age of a ref comes from every `core_npc_master` row with that
  refid, from `tes_watch.dm_scan` when present, from the index race flag when the index has kind `race`.
- Acceptance: `php tools/test_childsafe.php` prints ALL OK against the live DB, read-only:
  (a) the forms the verifier named are stopped for Мила Валентия (`0001A676`, decimal 108150):
  `['prid 0001A676','teskill']`, `['PRID 1a676','setav health 0']`, `['prid 0001A676','setrestrained 1']`,
  `['tesnear Мила Валентия','unequipall']`, `tesChildSafeText('0001A676.teskill')`; `['tesfight t 108150 | 20']` is
  dropped; `['tesfor t 108150,108142 :: teskill']` comes out as `tesfor t 108142 :: teskill` (108142 = Хульда);
  (b) sweep: after `prid 0001A676`, each verb of the guard's allowed list (`functions.php:1020-1027`) and
  each `tes*` command of the bridge dispatcher is dropped unless it is in `TES_CHILD_OK` or on the selection-free
  list of §8.8;
  (c) 12 sequences copied from existing callers (`court.php:239`, `tes_crime/lib.php:266`, `realm.php:101`,
  `festival.php:216`, `verify.php:43` and seven more) with an adult ref come out identical;
  (d) a ref with two rows, one of them a child, is a child (rows injected through a test hook, no DB write);
  (e) an unknown ref `0BADF00D`: `unequipall` dropped, `getav health` kept;
  (f) `['prid 0001A66E','teslove 20 kissing']` dropped, `['prid 0001A66E','teslove stop']` kept, constant off;
  (g) `tesChildSafeServer('fine', ['00013484'])` (Дорти) returns a reason, `('unjail', ['00013484'])` returns null,
  `('order', ['0001A66E'], 'ударь Люсию')` returns «в приказе назван ребёнок»;
  (h) the 40 lemma cases of §6.2;
  (i) the test greps `ext/` for `insert('skyrim_quest_action_outbox'`: 10 places today [ran]. Each is either behind
  `tesChildSafeCommands` (`tesWorldQueue`, `tesCrimeQueue`, `tesGodGuardQueueNearby` after this part) or on the test's
  list of code-built senders (`tes_russify`, `tes_book_value`, `tes_unfollow`, `tes_world/preprocessing.php:323`,
  the autosave at `functions.php:2001`, `tes_estate/lib.php:146`, `tes_gifts/functions.php:22`). A new place fails the
  test until someone classifies it.
  Also: `php tools/test_court.php` ALL OK and `php tools/test_ext.php` at its baseline (107 passed, 2 old failures).
- Depends on: nothing. It fixes holes of the live system, so it deploys alone (ext plugins, after `php -l`).

### Part 1: catalog and compiler
- Produces: the 69 ops of §6.5 as data with the fields of §8.4; `tesDmCompile`, `tesDmRenderStep`,
  `tesDmCatalog`, `tesDmCatalogLines`; builders and parsers for every command of §8.7.
- Contract in: plan of §8.1; `$resolve` of §8.2. Contract out: compiled plan of §8.2. Pure: no DB, no queue.
- Acceptance: `php tools/test_dm_compile.php` prints ALL OK, stub resolver, no DB:
  (a) a plan with an unknown op, a missing parameter and a bad selector yields three errors and zero sequences;
  (b) **child sweep over every op.** The test loads `catalog.json`, asserts 69 entries, asserts that each has every
  required field of §8.4 and that `class`, `child`, `harm`, caps, `undo`, `min_bridge` equal the table of §6.5 (the
  table is a fixture) and that every op with `harm: true` has a `verify` and an undo kind other than `skip`. For
  each entry it compiles the entry's `example` at bridge 17 and at bridge 15 with a resolver
  that answers every actor selector (`who`, `to`, `from`, `vs`, `with`) with four targets: an adult `0000AAAA`, a
  child `0000C0DE` named «Тестдитя», a person of unknown age `0000F00D`, and the player. For `child: never` ops: the
  strings `0000C0DE`, `49374`, «Тестдитя», `0000F00D` and `61453` occur in no command, server call, native call or
  local call of the step, and both targets sit in `skipped` with `child` and `unknown_age`; with a resolver that
  answers `to`, `from`, `vs`, `with` with the child alone the step is error `child_target` and has zero sequences.
  For `child: benefit` ops every command rendered for the child is in `TES_CHILD_OK`. For `order`, `director` and
  `rule_add` a text «ударь Тестдитя» is error `child_named`; for `console` the texts `0000C0DE.kill`,
  `0000F00D.unequipall` and `{npc:Тестдитя}.moveto player` are error `child_named`;
  (c) the sweep proves itself: run on a copy of the catalog in which `kill.child` is `benefit`, check (b) must fail;
  (d) deferred steps: `s1 spawn_group`, `s2 fight who {"spawned":"s1"} vs {"near":true}` compiles with `s2`
  deferred and no sequences; `tesDmRenderStep(s2, [adult, child, unknown], 17)` renders no command with the child
  or the unknown ref; `{"step":"s9"}` and a `spawned` reference to a `heal` step are error `bad_step_ref`;
  (e) every sequence rendered in (b) passes `tesChildSafeCommands` unchanged and `tesChildSafeLast()` is empty;
  (f) `gather` of 45 targets at bridge 17 gives `tesgather` commands of ≤ 20 refs, ≤ 2 per sequence, 5 marked
  `over_cap`; at bridge 15 one target per sequence and 30 marked `over_cap`; `strip` of 12 at bridge 17 gives
  `tesfor` commands of 5, 5 and 2 refs in three sequences; `set_skills` of 10 gives lists of 5 and 5 (`setav` is
  not in `TES_BULK_OK`), `heal` of 30 gives lists of 20 and 10;
  (g) `give` with `from: "player"` renders `removeitem` on the player and `additem` on the receiver with the same
  count; `take … "n":"all"` renders a pre-probe `item:<form>` and no fixed count; an item `n` of 0 is `bad_param`;
  (h) a plan that asks for `teslove`, `sex` or op `roll` is an error; no template in the catalog holds `teslove`
  except `scene_stop`;
  (i) round trip: every report example of §8.7 parses, every builder output matches its row.
- Depends on: part 0.

### Part 2: selectors and perception
- Produces: `tesDmResolver`, `tesDmPerception`.
- Contract: SELECT only on `tes_game_index`, `core_npc_master`, `eventlog`, `tes_crime_jail`, `tes_watch`. The world
  snapshot of §8.2 is an argument; without it `near` and `here` use the last `infonpc_close` event.
- Acceptance: `php tools/test_dm_select.php` against the live DB, read-only, prints ALL OK (numbers [ran]
  2026-10-10):
  `{"in":"Вайтран"}` returns 50-70 targets (64 today), one per ref, with `0001A66E` (Хульда) and without `0004FA2F`
  (Амон Мотьер), `000786CB` (Легат Рикке), `0009725D` (Знатный гость), `3301C105` (second Скьор), `000B8E97` (a
  corpse); the seven children placed there (Брейт, Люсия, Мила Валентия, Дагни, Нелкир, Фротар, Ларс Сын Битвы) have
  `age = child`; `outside` ≥ 20;
  `{"name":"Хельгу"}` never returns Хульда; `{"name":"Ингун Черный Вереск"}` never returns Скульвар; the player's
  name returns `player`; `{"name":"Милу Валентию"}` has `age = child`; `{"name":"Назима"}` returns `0001A6A4`;
  `{"name":"Джон"}` returns a target with `shared = true`;
  `{"in":"Вайтран","sex":"female"}` returns only women and a count of `unknown_sex`;
  `{"in":"Вайтран","wounded":true}` is error `filter_needs_scan`; with the fixture scan
  `tools/fixtures/dm_select/scan.txt` `{"near":true,"wounded":true}` returns the fixture's two wounded, and a fixture
  ref with flag C that CHIM does not know has `age = child`;
  `{"best":{"type":"armor","slot":"body","armor_class":"heavy"}}` returns one FormID that exists in the index with
  `ar` equal to the maximum of that filter;
  `{"place":"Гарцующей кобыле"}` returns cell `WhiterunBanneredMare` (`0001605E`, not `5C2E6E71`), `anchor_kind`
  `host` on today's index, an anchor that is an adult known ref placed in that cell and neither `03003F5E` (Люсия)
  nor `0004FA2F`; `{"place":"таверне"}` is error `ambiguous`;
  no SQL string in `select.php` and `perception.php` holds `~*`, `ILIKE` or `lower(` (grep).
- Depends on: parts 0 and 1.

### Part 3: executor, verification, undo, fake game
- Produces: `dm/schema.php`; `tesDmBind`, `tesDmRun`, `tesDmUndo`, `tesDmSayRu`; `TesDmTransport` with
  `TesDmGameTransport` and the fake; the local-op registry with the handler of op `undo`.
- Contract: §6.6, §6.7, §6.9; shapes of §8.2; tables of §8.6.
- Acceptance: `php tools/test_dm_exec.php` prints ALL OK, fake transport only, scratch schema:
  (a) gather 40 with 2 children, 3 missing, 1 jailed → exact counts, the Russian line names the jailed one;
  (b) **every op against the fake**: for each catalog entry the `example` runs with a child and an unknown-age
  person among the targets; the fake's log of `send`, `server` and `native` calls holds nothing aimed at either for
  `never` ops;
  (c) leak: a compiled step doctored to hold `prid <child>`, `unequipall` goes through `TesDmGameTransport::send`
  with the queue function stubbed: the command is dropped, the task ends `failed`, `leak = true`, the next step is
  not sent;
  (d) the fake emits a foreign report outside the markers → ignored;
  (e) the fake goes silent → `unverified`, never `done`;
  (f) journal first: inside the fake's `send` the scratch tables already hold the `planned` rows of that sequence;
  (g) crash: an exception thrown from `reports` after `send` leaves `sent` rows; `tesDmUndo(['last' => true])`
  probes, undoes the two targets where the fake applied the command, marks the third `not_applied`;
  (h) `set_av` then undo → the fake's value equals the value before; `weather` + `hour` then undo → restored; kill
  then undo with the target alive in the fake → no `resurrect` is sent; undo `['minutes' => 5]` over three tasks runs
  newest first; `dress` on a child is not reversed;
  (i) legacy: a `prid` miss produces the abort row; the bracket closes, the target is `failed: not_found`, the next
  target is sent;
  (j) harm pacing: `strip` of 12 at bridge 17; the fake answers the second probe with a foreign ref → the third
  sequence is never sent, its targets are `skipped: halted`;
  (k) deferred: `spawn_group` then `fight` on `{"spawned":"s1"}` uses the refs of the fake's `tesspawn` report; a
  step with `if … "is":"done"` after a failed step is `skipped: condition`;
  (l) bind: a plan with `{"near":true}` sends exactly one `tesworld` + `tesscan` sequence before the first write; a
  plan without game selectors sends none;
  (m) grep: `exec.php`, `verify.php`, `undo.php` hold no `skyrim_quest_action_outbox`, `tesWorldQueue(`,
  `herikaQueueGodCommands(` or `tesGodGuardRunServer(` outside class `TesDmGameTransport`;
  (n) after the test no `tes_dm_scratch_%` schema exists and `public` has no `tes_dm_*` table.
- Depends on: parts 0, 1, 2.

### Part 4: bridge v17
- Produces: the 18 rows of §8.7, the lock restamp, the skip-forward sequence loop, the seven hardened commands,
  the two verb lists; the previous `.pex` kept as `.pex.bak-v16`; a v17 section of 10 console lines in
  `docs/in-game-tests.md`, one line per "W?" row of §2 among them.
- Contract: §8.7 exactly: names, argument order, signed decimal ids, report text.
- Acceptance without the game: (a) `PapyrusCompiler.exe` exits 0 with the import list of the toolchain note;
  (b) `php tools/test_bridge_contract.php` prints ALL OK: each command name of §8.7 is dispatched in
  `TESRunAndReport`; `tesversion@@17` is present; `TESConsoleLockAt` is written inside the per-ref loop of `tesfor`,
  `tesgather`, `tesprobe`, `tesscan`, `tesfight`, `tesspawn`; `ExecuteConsoleCommandSequence` holds the literal
  `error: skipped, target not found` and no `return` between a failed `prid` and the end of the loop; the `tesmark`
  handler calls `SetSelectedReference(None)`; each of the seven hardened handlers and `tesfor`, `tesgather`,
  `tesfight`, `tesspawn`, `tesrace` has `IsChild()` before its first state-changing call; the Papyrus CHILD_OK and
  BULK_OK arrays equal `TES_CHILD_OK` and `TES_BULK_OK`; every `logMessage` literal of the new commands parses with
  `dm/bridge.php`. In-game checks stay with the owner.
- Depends on: part 0 (verb lists) and part 1 (`dm/bridge.php`). Deploy needs the owner: a game restart.

### Part 5: planner and recipes
- Produces: `tesDmRecipe`, `tesDmPlan` with ≤ 3 turns; the prompt of §8.3; 20 recipes; kits `thief`, `alchemist`,
  `smith`, `archer`, `mage`, `dragon_fighter`.
- Contract: `$llm(array $messages, array $tools): ?array` is injected (the worker passes `tesAgentLlm`); the planner
  offers one tool, `submit_plan`; a reply without a valid call counts as a turn.
- Acceptance: `php tools/test_dm_plan.php` prints ALL OK with a stub `$llm` that replays fixtures:
  (a) recipe `gather` returns the three plans of §8.5 for the goals of tasks 185, 168, 197, word for word as stored,
  and null for the other 16 distinct plain goals of `tes_agent_tasks` (fixture copy of the 19);
  (b) each of the 20 recipes matches its 3 sample phrases (Russian; one in the Narrator's third person or
  infinitive, one as the player said it, one with speech-to-text noise: lower case, no punctuation, a garbled
  place) and compiles; none matches the 20 negative phrases taken from real lines, among them «Но никто не
  собрался», «Ну чего ты собираешь всех?»;
  (c) abstain: «Всех мужчин около меня собрать, всем броню стражников выдать» returns null;
  (d) a fixture with a compile error leads to exactly one repair turn; a third bad reply ends with `impossible`,
  zero game commands;
  (e) the rendered prompt is ≤ 12000 characters and holds no Cyrillic outside the Facts block.
- A paid evaluation of the real model on the benchmark (about 60 calls, estimated ≤ $0.06) needs the owner's yes
  (ROADMAP rule 6) and is not part of acceptance.
- Depends on: parts 1, 2.

### Part 6: campaign memory, standing rules, encounters
- Produces: `tesDmCampaignLine`, `tesDmRulesDue`; local handlers registered for `plot_open`, `plot_advance`,
  `plot_close`, `fact_set`, `fact_forget`, `rule_add`, `rule_remove`; 8 encounters (bandits, archers ambush, wolves,
  undead, dragon, caravan, tavern brawl, assassins). No dice.
- Contract: §6.10, §7.2, §7.3; tables `tes_dm_campaign`, `tes_dm_rules` through `tesDmTable`.
- Acceptance: `php tools/test_dm_table.php` prints ALL OK in the scratch schema: the campaign line is ≤ 600
  characters with 5 plots and 12 deeds stored; a `seen` rule fires once for a matching `infonpc` sample and
  respects its cooldown; an 11th active rule is refused; a rule whose plan would strip a child compiles to zero
  commands for the child at firing; `rule_add` then undo removes the rule row; every encounter entry compiles with
  `spawn_group` and none has a child-race base; after the test the scratch schema is gone.
- Depends on: parts 1, 3.

### Part 7: index enrichment
- State: built and loaded on 2026-10-10 (commits 652d329, 3b9923a; F27): `sex`, `race`, `child`, `unique`,
  `essential`, `fac`, `tpl` on bases, cell `loc`, location `parent`, kinds `race`, `shout`, `word`. What follows is
  the rest of the part.
- Produces, new: `actor.extra.dis` (record flag 0x800, initially disabled) and `actor.extra.xesp` (the ref has an
  enable parent), read the way `tools/cell_refs.py` reads them for REFR; `cell.extra.anchor`: for an interior cell
  the FormID of its first REFR whose base is the STAT with EditorID `COCMarkerHeading`, else `XMarkerHeading`, else
  its first REFR with an `XTEL` subrecord (a load door). The bases are found by EditorID, never by a hard-coded
  FormID. Then: a TSV built on the owner's Windows Python and loaded.
- Contract: the TSV format of the file header is unchanged; new keys only inside `extra_json`.
- Acceptance: `python tools/test_index_enrich.py <out.tsv>` prints ALL OK: the existing checks (`HousecarlWhiterun`
  `sex = F` and `unique`; the bases of Люсия and Мила Валентия have `child`; cell `WhiterunBanneredMare` has `loc`
  whose `parent` chain reaches `WhiterunLocation`; ≥ 20 rows of kind `shout`; old kinds within 1 %), plus:
  `AmaundMotierreEndRef` and `CWBattleRikke` carry `dis` or `xesp`, `HuldaREF` and `NazeemREF` carry neither;
  `WhiterunBanneredMare` has an `anchor` whose FormID is not an `actor` row; the test prints how many named
  interior cells have no anchor. Loading the TSV into `tes_game_index` changes data: owner's yes.
- Depends on: nothing. Until the three fields are loaded: `in:` stays tier A and anchors are hosts.

### Part 8: integration, benchmark gate
- Edits: `worker.php` (plan path by default; the loop behind `--loop` with `TES_AGENT_MAX_STEPS` 6; `turns` and
  `mode` saved); `lib.php` (`tesAgentStart` gains `$said`; `tesAgentEnsureTable` calls `tesDmEnsureTables`; line 164
  keeps the old CREATE text and is followed by the same call; `tesAgentUndoLast` hands tasks with `step_id <> ''`
  to `tesDmUndo`); `functions.php` (passes the player's line as `$said`); `context_pre.php` (English line with the
  campaign line; the sentence on scenes stays); `ext/tes_world/realm.php` (undo window phrases → op `undo`);
  `ext/tes_world/postrequest.php` (agent patrol branch removed in favour of rules); `playthrough_tables.txt` (four
  names); `panel.php` (rules, per-step outcome, `planned` rows older than 5 minutes, `childsafe_leak` in red);
  `tools/ensure_playthrough_tables.php` (line 24 followed by `tesDmEnsureTables`). New:
  `ext/tes_agent/preprocessing.php` (the rule tick).
- Contract: prefixes of §6.1; `tesAgentStart` stays callable with its old five arguments.
- Acceptance: (a) `php tools/test_ext.php` at its baseline (107 passed, 2 old failures) and
  `php tools/test_court.php` ALL OK;
  (b) `php tools/test_dm_bench.php` prints one line per benchmark row and ALL OK: each of the 47 "W" rows that do
  not wait for the owner compiles from its recipe or its reference plan and runs to `done` against the fake game;
  each of the 8 "S" rows ends `partial` or `done` with its limit in the Russian line, rows 8 and 29 name their
  substitute; rows 44, 46, 47 end `impossible` with the reason in Russian and send nothing; rows 45 and 48 are
  printed as `waits for the owner` while `TES_DM_HIDDEN_CHECKS` is false; turns per row equal the route's budget
  (0 recipe, 1 plan); the fake holds two children in every scene and no command, server call or native call in
  any row is aimed at them;
  (c) «устрой Айрилет сцену со мной» as a goal ends `impossible`, the fake's log holds no `teslove`;
  (d) `php ext/tes_agent/worker.php --goal "собрать всех жителей Вайтрана в Гарцующей кобыле" --dry` ends `done`
  with zero rows added to `skyrim_quest_action_outbox`;
  (e) with a scratch schema holding the six-column `tes_agent_undo`, `tesDmEnsureTables` adds the columns and
  `tesAgentJournal` still inserts.
- Depends on: parts 0, 1, 2, 3, 5, 6.

### Part 9: hidden checks (owner has not confirmed yet; build last)
- Produces: `tesDmCheckKind`, `tesDmSheet`, `tesDmCheck`; the local handler of op `check`; the `check:` prefix; the
  speech hook; all inert while `TES_DM_HIDDEN_CHECKS` is false.
- Contract: §7.1; table `tes_dm_checks`.
- Acceptance: `php tools/test_dm_checks.php` prints ALL OK in the scratch schema: with a seeded `$rng` skill 80,
  affinity 40, NPC speech 9, medium stake gives chance 80; skill 15 against speech 60 at affinity −50 with a large
  stake gives 5 (the floor); a bribe of 500 gold to a level 10 NPC adds 25; a real `tesstate` line copied from task
  201 step 8 parses to Speechcraft; a null sheet returns `no_check`; a target in combat returns `no_check`; a child
  target returns `no_check`; the same NPC and kind inside 10 game minutes returns the stored row; no string
  produced for a prompt holds a digit or the words check, roll, dice, skill-name; with the constant false the hook
  registers nothing and `tesDmLocalHas('check')` is false.
- Depends on: parts 3, 8, and the owner's yes.

---

## 10. Assumptions

| # | Assumption | Status | If false |
|---|---|---|---|
| A1 | The index answers "who is placed in cell X" | verified [ran]: 181 Whiterun actors, 91 named, 64 refs also known to CHIM | bulk by place falls back to a scan of where the player stands |
| A2 | Unknown `tes*` commands are still logged with their text, so `tesmark` brackets work on bridge 15/16 | read (`psc:562-569`), not run | on old bridges one target per sequence already bounds the damage; correlate by the echoed `prid` row |
| A3 | The v15 lock keeps one sequence's reports contiguous | read; the v15 game session of 10-06 is the only live evidence | bulk waits for v17; legacy stays one target per sequence |
| A4 | A cheap model writes a valid plan in one turn for most non-recipe wishes | **not verified**; no model call was allowed | recipes still give 0-turn coverage of 20 wish families; the cap stays 3 turns; consider the stronger connector 12 for the planner only |
| A5 | `tesfor` may select and execute for 20 refs in one Papyrus call without `Utility.Wait` | not verified | add `Utility.Wait(0.05)` per ref: 20 refs take about 1 s more; the lock restamp keeps it safe |
| A6 | A command or report of ≤ 450 characters survives the plugin transport | verified for reports ≤ 500 [ran]; commands that long not verified | cut ref lists to 10 per command |
| A7 | `MoveTo` and `Game.GetForm` reach named NPCs in unloaded cells | read (the feast and `move_npc` do it by `prid`; 51 of 4880 `prid` failed) | the outcome lists them as `missing`; nothing breaks |
| A8 | `MoveToMyEditorLocation` sends a moved NPC home | not verified | undo of `gather` becomes "they walk home by their packages" and says so |
| A9 | Advancing `GameHour` past 24 rolls the day safely with this mod list | not verified | `skip_time` stays "W?"; fallback: the player's own wait menu, said honestly |
| A10 | SKSE `WornObject.CreateEnchantment` and `SetDisplayName` work on 1.5.97 with Requiem | not verified | rows 7 and 8 stay F and X |
| A11 | The vanilla vampire and werewolf quest scripts are callable under RFAD | not verified | row 13 stays F |
| A12 | Cyrillic in a command argument (`tesitem` name) survives the outbox → Papyrus path | not verified; the core strips Cyrillic on its own path (`functions.php:845`) | the name goes by a StorageUtil string set through a transliterated key, or row 8 loses the rename |
| A13 | A console sequence continues after `coc` loads a cell | not verified | row 28: two sequences with `await` between them (already the design) |
| A14 | `tesstate` on `00000014` returns the player's base skills | verified [ran]: task 201 steps 8 and 38 | none |
| A15 | A child is recognised before any command | partly verified: 9 children known to CHIM [ran]; the engine flag exists (`IsChild()` at `psc:159, 301, 436`); the index flags 53 child bases (F27) | unknown age is treated as a child at compile and at the gate (already the design) |
| A16 | The Narrator keeps emitting `goal:` for wishes | verified [read]: 114 agent tasks exist | none |
| A17 | Bridge 16 is installed and is the base for 17 | read (ROADMAP §4); the game last ran 15 (`tes_watch.bridge_ver` [ran]) | part 4 diffs against the v16 source in the repo, the file read here (`tesversion@@16`) |
| A18 | The stem rule resolves case forms of names without a morphology library | verified [ran]: 40 of 40 on live names | add the missed ending to the list (data); pass 3 covers people nearby |
| A19 | CHIM stores a `core_npc_master` row only for people who stood near the player, so tier A holds no never-enabled quest ref | verified for Whiterun only [ran]: the quest refs, corpses and staging refs of F23 have no CHIM row; how CHIM adds rows was not read | bridge 17 skips disabled refs (`off=`); on bridge < 17 add a `getdisabled` pre-probe per target |
| A20 | The compiler is at least as strict as the gate, so the gate never drops a compiled command | by construction; part 1 check (e), part 3 check (c) | the task ends `failed` with `childsafe_leak`; the child is untouched |
| A21 | Record flag 0x800 and `XESP` mark quest-controlled placed actors | not verified for ACHR; `tools/cell_refs.py` reads both for REFR. `unique` alone keeps Амон Мотьер and Знатный гость [ran] | tier B stays off; `in:` stays tier A |
| A22 | Named interiors hold a `COCMarkerHeading`, an `XMarkerHeading` or a load door, and `MoveTo` it works for NPCs | not verified | the anchor is a host and the fact line says so |
| A23 | The whitelist gate breaks no existing sequence aimed at an adult | read: targets of `tes_world` and `tes_crime` come from `core_npc_master` (`tesWorldRefOf`, `lib.php:454`); part 0 check (c) | a verb joins `TES_CHILD_OK` only when it is a read, heal, dress or release; anything else is a finding for the owner |
| A24 | Skipping forward after a failed `prid` is as safe as ending the sequence | read (`psc:109-133`) | keep the abort; the executor already handles the abort row |
| A25 | `getincell <EditorID>` answers in the console for a selected actor | not verified | on bridge < 17 a place works only when the player stands there; else `no_anchor` |
| A26 | The Narrator's goal keeps the verb and the names a recipe needs | verified for the three gather goals [ran]; other families not | the planner takes the wish: 1 turn instead of 0 |

---

## 11. What can go wrong in production

| Risk | How it is noticed | How it is undone |
|---|---|---|
| A bulk op hits the wrong people | `tes_dm_steps` rows whose probe answer names another ref; harmful steps halt after 5; the panel shows per-target status | `undo` of the task; `tessave` before ops that cannot be undone |
| A command for a child reaches the transport | the gate drops it: `[childsafe]` line in the error log, task `failed` with `childsafe_leak`, red on the panel; on bridge 17 a `child=` list in a report | nothing ran; fix the catalog entry; part 1 check (b) gets the case |
| The gate drops a command an existing plugin needs for an adult | `[childsafe]` lines naming an adult ref or `unknown`; an order that used to work does nothing | restore the previous `childsafe.php` from the deploy backup; part 0 is one file |
| People CHIM never met are left out of place selectors | the fact line: «не встречал: 30» | build and load `dis` and `xesp` (part 7, owner's yes); that turns tier B on |
| Recipe false positive: plain talk starts a wish | task row with `mode = recipe` and a goal that reads as chat; the Narrator's line says what was done | `undo`; tighten the recipe's `veto` (data, no deploy of code) |
| The planner writes a harmful but valid plan the player did not ask for | the plan is stored in `tes_agent_tasks.plan`; `harm` ops over 3 targets need a plural in the wish text, else a confirmation | `undo`; the autosave exists |
| 40 people in one interior hurt the frame rate or crash | the owner sees it; `max_targets` is data | lower the cap; `home` on the set |
| The queue backs up and sequences race again | `busy()` true for > 30 s; `unverified` outcomes | the executor stops sending; rules stop; nothing is retried blindly |
| A worker dies in the middle of a step | `tes_dm_steps` rows `planned` or `sent` older than 5 minutes, listed on the panel | `undo` probes them and reverses only what was applied (§6.9) |
| Bridge 17 breaks every order | all `tes*` reports say "not found" or stop; `tesBridgeVersion()` ≠ 17 | rename `.pex.bak-v16` back, restart the game (ROADMAP §4 procedure) |
| Standing rules loop | `tes_dm_rules.runs` grows fast; limits of §6.10 | `UPDATE … SET active = false` from the panel button; undo of the creating task removes the rule |
| The key runs out mid-task | `tesAgentLlm` returns null: the task ends `failed` with «модель не ответила» before any game command (planning precedes execution) | none needed |
| A CHIM update changes `tesGodGuardRunServer` callers or the outbox | `tools/test_ext.php`, `tools/test_childsafe.php`, `tools/test_dm_exec.php` after `tools/after_update.sh` | `tools/after_update.sh`; the plugin files are ours |
| A test leaves a scratch schema | `\dn` shows `tes_dm_scratch_*` | the next test run drops it; `DROP SCHEMA … CASCADE` by hand |
| Old save loaded: campaign rows from "the future" | plots mention events that did not happen | until design P5 lands: panel button "forget after game time T" using `gamets` |

---

## 12. Not determined

- Model turns per historical task: the transcript stores tool calls only. Part 8 adds `turns`.
- Planner quality and real cost: no model call was allowed.
- Whether bridge 16 works in the game: the last game session ran bridge 15.
- The transport's maximum command length from the outbox to Papyrus.
- How SNQE quests are authored, and whether a journal entry can be created from the server: tables `sneq_quests`,
  `quest_assets` exist; their API was not read.
- The token size of the Narrator `Instruction` request that reports a finished task.
- Whether `Game.SetPlayerReportCrime`, `SpawnDoor` through ScriptProxy, and `Actor.SetRace` on a named NPC behave
  with this mod list.
- Whether the eight rows marked "W?" in §2 hold: each needs one console line in the game (part 4 lists them).
- The owner's decision on hidden checks (§7.1, part 9).
- Whether `tools/test_ext.php` and `tools/test_court.php` stand at 107 passed / 2 old failures and ALL OK today: the
  numbers are read from PLAN row P3-done and `docs/FROZEN.md`; the tests were not run for this revision.
- The repo moved while this revision was written (commits e170c3d, 3b9923a, 2af6765, 7575439; the index reload;
  `docs/FROZEN.md`). The line numbers cited in F19-F28 and §6-§9 were re-checked against HEAD 7575439; the counts
  marked [ran] were taken between 15:50 and 16:25 on 2026-10-10.
- How the CHIM core decides to add a `core_npc_master` row (A19), and why 60 rows share a refid.
- What `tes_gifts/functions.php:22` and `tes_estate/lib.php:146` can send for a child: their callers were not read
  in full; part 0 check (i) keeps them on a named list.
- Which rows the verifier counted for "17 live-evidenced": the recount here gives 17 by moving rows 37 and 51 to
  live (guard log 1504 and 1377) and leaving rows 2 and 53 on code only.
- Whether `AmaundMotierreEndRef` and the civil-war refs are initially disabled or enable-parented (A21).

---

## 13. Changes after verification (2026-10-10)

Verifier's verdict (PLAN row P0): build after these fixes; architecture B stands. Finding → what changed.

| # | Finding | Changed in |
|---|---|---|
| 1 | Compiled ops bypass the guard; `tesChildSafeCommands` has holes | §6.8: one gate for every path, a whitelist, a path table; §6.6: the transport is the only exit and calls the gate in `send`, `server`, `native`; §8.8: API and verb lists; §8.7: seven hardened commands, `tesfor` / `tesgather` / `tesfight` / `tesspawn` ask `IsChild()`; new part 0; part 3 checks (b), (c), (m) |
| 2 | Class table covers about 17 of 68 ops; marry, fine, order, gather can hit children | §6.5: all 69 ops with class, child policy, harm, caps, undo; §8.4: the fields are required and `example` drives the tests; part 1 checks (b), (c) |
| 3 | Late-bound sets contradict a pure compiler | §6.4: `tesDmBind` before compile, deferred steps, `tesDmRenderStep` shared by compiler and executor; §8.2: world snapshot, `defer`, `if`; part 1 check (d), part 3 checks (k), (l) |
| 4 | Journal row before the send; `tes_agent_undo` schema conflict | §6.9: pre-probe → rows → send → verify, row states, crash rule; §8.6: the old CREATE word for word, then `ADD COLUMN IF NOT EXISTS`; part 3 checks (f), (g); part 8 edits `lib.php:164` and `tools/ensure_playthrough_tables.php:24`, check (e) |
| 5 | Exact-match names fail on Russian cases | §6.2: `tesLemmaMatch`, stem rule, passes, candidate order, 40 of 40 [ran]; §8.3: "dictionary form" line; part 0 check (h); part 2 name cases |
| 6 | The recipe regex matches 0 of 3 real goals | §8.5: recipes as features (verb stems, veto, typed slots in any order, abstain rule), 3 of 3 [ran]; F24; part 5 checks (a)-(c) |
| 7 | Selectors: non-residents, quest refs, shared guard refids, wrong anchor, `race ~* 'реб'` | §6.3: tier A / tier B residents, one target per ref and `shared`, anchor order static → player → verified host, the one SQL predicate; §6.8 rule 2 (any row, no `LIMIT 1`); F21-F23; part 2 acceptance; part 7 adds `dis`, `xesp`, `anchor` |
| 8 | Plan language: give direction, quantity, check / on_success, health and hostile filters, an undo op | §8.1: `from`, `to`, `vs`, `with`, item `n`, `if` in place of `on_success` / `on_fail`; §6.3: `hp`, `wounded`, `hostile`, `in_combat` and the scan rule; op `undo` in §6.5, §6.9; §8.3 prompt lines; part 1 check (g) |
| 9 | Bridge v17: `tesfor` over 20 refs breaks the 30 s lock; a `prid` miss aborts the sequence | §8.7: lock restamp per ref, BULK_OK or 5 refs, skip-forward loop; §6.6: 5 refs per harmful `tesfor` with a probe between, one target per sequence on bridge < 17, the abort row closes the bracket; part 4 check (b); part 3 checks (i), (j) |
| 10 | Part 4 depends on part 1; nobody owns the dispatch of roll / check / plot / rule; part 6 needs a scratch schema | §9: order and the file table, part 4 after parts 0 and 1; §6.6 and §8.9: the local-op registry (part 3 owns it, parts 6 and 9 register, part 8 owns the rule tick, part 9 the `check:` prefix); §8.6: `tesDmSchema`, scratch schema for every test |

Owner decisions of 2026-10-10:

| Decision | Changed in |
|---|---|
| No dice in combat, none shown; hidden non-combat checks only, unconfirmed, built last | §2 rows 44-48 and totals (3 rows out of scope, 2 wait for the owner); §7.1 rewritten; op `roll` removed; table `tes_dm_checks`; part 9 behind `TES_DM_HIDDEN_CHECKS` |
| Scenes belong to SHARMAT | §6.5 closing note; §6.8 rule 5; §8.3; `scene` renamed `director`, `scene_stop` added; part 1 check (h), part 8 check (c). No benchmark row needed a scene start, so no row left the benchmark for this reason |
| Children: never, by any path | §6.5 (`never` for 38 ops; `benefit` only for heal, dress, release, scene_stop; 26 have no actor target; `rule_add` compiles its plan under the same rules), §6.8, part 0 |
| Translation merged; existing files may be edited | §9 file table |

Also corrected: "works today" is 19 by code path, 17 with live evidence (§2); F7 and F9 carry their state after
commit 780852c; the test baseline is 107 passed, not 97; F3, F8, F27 and part 7 describe the index as loaded on
2026-10-10.
