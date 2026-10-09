# God-Narrator v2: the wish compiler (design, 2026-10-09)

Status: design only. Nothing here is built. Read-only work: SELECTs on the live DB `dwemer`, file reads, no LLM call,
no game run. Marks: **[ran]** = I ran the query or command today; **[read]** = I read the code or doc, did not run it;
**[not verified]** = neither.

Language rule for every part: text sent to a model is English; text the player hears or reads is Russian; regexes
that match his speech are Russian.

Contents: 1 findings · 2 benchmark of 60 wishes · 3 where the steps went · 4 architectures · 5 recommendation ·
6 design · 7 tabletop layer · 8 contracts · 9 parts for builders · 10 assumptions · 11 production risks ·
12 not determined.

---

## 1. Findings that constrain the design

| # | Finding | Evidence |
|---|---|---|
| F1 | 73 % of the calls of failed tasks are lookups, not deeds: 911 server lookups (`find`, `npc_info`, `relationships`, `quest_log`) + 175 game reads of 1487 calls. Writes are 395 (26.6 %), and 74 of those were refused or errored. | [ran] `tes_agent_tasks.transcript`, statuses failed + gave_up |
| F2 | The agent cannot ask "who is in X". `find kind=npc` matches a name substring only (`ext/tes_agent/worker.php:265-275`). Bulk wishes guess names one at a time. | [read] + tasks 185, 167 |
| F3 | The index can answer "who is in X" today: `actor.extra = {base, cell}` for 15810 placed actors; 181 actors sit in cells whose EditorID starts with `Whiterun`, 7 in `WhiterunBanneredMare`. It cannot answer sex, race, faction or child: `npc.extra` is `{}`; cells carry no location. | [ran] `tes_game_index` |
| F4 | Game reports are matched by "rows with id > N" (`worker.php:164-181`), not by sequence. 13 of 334 game reads returned another command's report. Task 201 step 26: `get_state player` returned Карлотта Валентия. | [ran] |
| F5 | The loose name resolver returns the wrong person: «Хельга» → Хульда (tasks 133, 167, 185), «Шаман» (the player) → Огман Магодин (13, 198, 201), «Ингун Черный Вереск» → Скульвар Черная Рукоять (185). Cause: Levenshtein ≤ 2 on any name word (`ext/tes_god_guard/functions.php:86-111`). | [ran] 11 cases |
| F6 | `set_quest_stage` never works: the tool sends `setstage`, the guard refuses `setstage` for everyone (`functions.php:1031`, `:1795`). Tasks 9, 13, 146 hit it. | [read] + [ran] |
| F7 | The agent write path skips the child filter. `tesAgentWrite` goes guard → core `herikaQueueGodCommands` (`worker.php:213-218`); only `tesWorldQueue` calls `tesChildSafeCommands` (`ext/tes_world/lib.php:424-425`). The guard checks children for `unequipall`, `unequipitem`, `giveall`, `clone`, `sex` only (`functions.php:1417, 1520, 1536, 1556`); `kill` on a child reaches the bridge, where `teskill` runs `TESMortal` + `Kill()` before its child branch (`AIAgentQuestProgressionBridge.psc:284-323`). | [read] |
| F8 | Children are known only when CHIM has met them: `tesChildSafeIsChildRef` reads `core_npc_master.race` (`childsafe.php:11-21`). 9 rows have race `Ребенок`; 242 NPC rows in total. A child never spoken to counts as an adult. | [ran] |
| F9 | The worker system prompt allows jailing children: «всё прочее (арест, тюрьма, привести, наградить) с ними делать можно» (`worker.php:696`). The brief forbids it. | [read] |
| F10 | Undo of agent tasks has never run: table `tes_agent_undo` does not exist in the live DB. `tes_undo` (fast orders) has 6 rows, 0 undone. `tesAgentInverse` returns null for `moveto`, `setav`, `fw`, `set gamehour`, `placeatme` (`ext/tes_agent/lib.php:123-155`). | [ran] + [read] |
| F11 | The six "prepare Lydia" runs of 2026-10-06 (tasks 209-214) were dry runs: every write result carries `"dry_run":true`, `finish` skips its checks in dry mode (`worker.php:657-659`). 19 of 114 agent runs are dry: 1-10, 12, 43, 77, 209-214. The post-fix agent has never run against the game. The game last reported at 2026-10-06 18:03; bridge in that session: 15 (`tes_watch.bridge_ver`). | [ran] |
| F12 | A plain task may take 60 model turns and 600 s: `TES_AGENT_MAX_STEPS = 60`, loop bound `$turns < $maxSteps` (`worker.php:21, 52, 732`). ROADMAP says 10. | [read] |
| F13 | Console pace: 0.29 s median, 0.35 s p90 between reports inside a burst (12641 gaps). A 10-command sequence takes about 3 s. `prid` answered "not found" 51 times of 4880. | [ran] `tes_god_console_log` |
| F14 | Report text is cut at 500 characters (`ext/tes_god_console/preprocessing.php:35`); 85 of 14149 rows are cut. `tesinspect` lists lose their tail. | [ran] |
| F15 | Money: 114 agent runs cost $0.543 in total; a finished run $0.0040 on average (16.9 calls), a failed one $0.0053 (25.3 calls). Key limit $2, $1.96 left on 2026-10-06. | [ran] |
| F16 | What works with zero agent turns today: 57 `fast` rule orders, and the Russian-regex handlers in `ext/tes_world/preprocessing.php:107-140` (court, gods, craft, services). Each handler is PHP code; a new kind of wish means new code. | [ran] + [read] |
| F17 | The Narrator writes set programs on its own: `foreach {npc} in nearby_actors.filter(...)`, seven times, all refused (`ext/tes_agent/functions.php:29-35`). The model wants set semantics and has no legal form for them. | [read] |
| F18 | Native pieces to reuse: `herikaInvokeRolemasterCliCommand('instruction', …)` (Director scene, up to 12 nearby actors, one core model call), `('spawn', …)` (Create_New_NPC), `SpawnNPCRaw` rolecommand, `tesGodGuardRunServer` for relation, memory, marriage, jail, fine, document (`functions.php:729`), `tesGodAutosaveIfNeeded` (`:1960`), `tesWorldQueue` (child filter + worn tracking), the delayed queue `tes_fest_queue` (`festival.php:22-56`), `chimRegisterPromptInjection`. | [read] |

---

## 2. Benchmark: 60 wishes a dungeon master grants

Today: **W** works · **P** partly · **F** fails · **X** impossible by engine. Evidence: (L) seen in live logs, (C) code path
read, not seen live. After: **W** works · **W?** works if an unverified engine behaviour holds · **S** substitute or
partial, said honestly. Needs: **s** server only · **b** bridge v17 · **i** index enrichment.

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
| 9 | «Раздай каждому в таверне по бутылке мёда» | F | bulk: one `additem` per person per turn | W | b |
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
| 19 | «Пересели Олаву в Рифтен» | F | `settle_here` anchors only where the player stands | W: move to an anchor in the cell + `tesroutine self` | b |
| 20 | «Заставь стражника станцевать» | F | `playidle` not in the guard list (tes_world sends it itself, 112 reports) | W: op `idle`, a data table of idles | s |
| **E** | **Groups and crowds** | | | | |
| 21 | «Собери всех жителей Вайтрана в „Гарцующей кобыле“» | F | task 185: 60 calls, 42 `find`, zero moves | W, capped at 40 per wish, rest named honestly | b (s slow) |
| 22 | «Пусть все люди Вайтрана появятся рядом со мной» | F | task 197: 36 `npc_info`, 20 moves, limit | W, same cap | b (s slow) |
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
| 37 | «Пусть Изольда влюбится в меня, и мы поженимся» | W (C) | relation + `marry` (`tesGodGuardMarry`) | W | s |
| 38 | «Сделай меня ярлом Вайтрана» | W (L) | title through `player.title` (ROADMAP ✅); task 13 failed on the faction route | W | s |
| **I** | **Combat encounters** | | | | |
| 39 | «Пусть на нас нападут пятеро бандитов» | W (L) | `player.placeatme {spawn:бандит} 5`, lands on the player's feet | W, at a distance | b |
| 40 | «Засада: трое лучников за спиной в тридцати шагах» | F | `placeatme` has no offset | W | b |
| 41 | «Пусть стражник и Назим подерутся» | F | `startcombat` not in the guard list; `tesduel` belongs to executions | W: `tesfight` | b |
| 42 | «Останови бой, все успокойтесь» | W (L) | `player.pardon`, `tespeace` | W | s |
| 43 | «Сделай этого дракона моим союзником» | F | a creature CHIM does not know; ROADMAP item 11 | W? crosshair ref + pacify | b |
| **J** | **Dice and skill checks** | | | | |
| 44 | «Брось d20» | F | the model invents the number | W | s |
| 45 | «Уговариваю стражника пропустить меня: проверка Красноречия» | F | no check mechanics | W | s |
| 46 | «Пробую вскрыть этот замок: проверка Взлома» | F | same; `unlock` not allowed | W | s b |
| 47 | «Кто кого перепьёт: я или Вилкас» | F | no opposed check | W | s |
| 48 | Narrator calls a saving throw on its own | F | no channel | W: `check:` prefix | s |
| **K** | **Rewards and punishments** | | | | |
| 49 | «Награди Лидию 500 золотых и новым мечом» | W (L) | `give_items` | W | s |
| 50 | «Посади Назима в тюрьму на три дня» | W (L) | `tes_crime`, fast orders 159, 183 | W | s |
| 51 | «Оштрафуй Белетора на 1000» | W (C) | `fine` | W | s |
| 52 | «Изгони Назима из Вайтрана навсегда» | F | no op; nothing enforces "навсегда" | W: relocate + a standing rule | b |
| **L** | **Scenes with several actors** | | | | |
| 53 | «Пусть Балгруф при всех отчитает Провентуса» | W (C) | native `Director_Command`; through the agent 36-44 calls (tasks 14, 24) | W: op `scene` hands the brief to the Director | s |
| 54 | «Устрой свадьбу Изольды и Микаэля в храме» | F | gather + marry + scene in order | W | b |
| 55 | «Суд над Назимом со свидетелями» | W (L) | `court.php` | W | s |
| 56 | «Караван каджитов, на него нападают бандиты» | F | two groups, positions, mutual hostility | W? | b |
| **M** | **Undoing** | | | | |
| 57 | «Верни как было» | P | code exists; never exercised (F10) | W | s |
| 58 | «Отмени всё, что ты сделал за последние пять минут» | F | undo covers the last task only | W | s |
| 59 | «Воскреси казнённого, и пусть он всё забудет» | F | no op removes a memory | S: he lives and loses the memories this system wrote; CHIM's own event log stays | s |
| 60 | «Верни прежнюю погоду и время» | F | no before-state is recorded | W | b |

**Today: 19 work, 4 partly, 35 fail, 2 impossible.**
**After (design target, not a measurement): 52 work, 8 substitute.** Of the 52, eight carry "W?" (4, 7, 13, 28, 30, 32, 43, 56):
they rest on an engine behaviour nobody has run. 25 of the 52 need bridge v17 or the index; 27 work on bridge 15/16.
Rows 21-23 work on the old bridge too, at about 3 s per 5 people.

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
| 1 coverage of the 60 | about 35: bulk partly, no dice, no encounters | **52 + 8 substitutes** | about 30: no DB, no unloaded actors | as A | about 25 |
| 2 turns per wish | 3-10 | **0-1, cap 3** | 0-1 | 2-6 at 9k tokens | 0 |
| 3 verified outcome | `finish.expect`, items and perks only | **per target, every op** | in game only | none | 3 kinds (`verify.php`) |
| 4 safety, undo | scattered (F7) | **three layers, before-state** | bridge only | guard | per handler |
| 5 new wish without code | no | **yes: JSON** | no | no (SQL + core) | no |
| 6 no core, one bridge | yes | **yes** | no | no | yes |

---

## 5. Recommendation

**Build B, the wish compiler.** It is the only option that passes criteria 1, 2 and 5 together, and it keeps what
already works: the `goal:` hand-off, the guard for raw console text, `tesWorldQueue`, the fast orders, the gods.

Main rejected alternative: **A**, the roadmap's own plan. It leaves the model in a loop, so bulk wishes and wandering
stay expensive, and each new wish is new PHP.

The loop worker stays as an escape hatch (`--loop`, 6 turns) for wishes the planner marks `"explore": true`.

---

## 6. Design

### 6.1 Flow and turn budget

```
player speech
  │  ext/tes_world/preprocessing.php: existing handlers (court, gods, fast orders)         0 model calls
  │  ext/tes_agent/preprocessing.php (new): recipes with "pre": true (dice, checks)        0 model calls
  ▼
Narrator model turn (always paid; unchanged)
  │  God_Command target: "goal: <words>" | "ask: <question>" | "roll: …" | "check: …" | console text (→ guard)
  ▼
ext/tes_agent/functions.php → tesAgentStart → worker.php
  1 recipe match on the goal text (dm/recipes.json)      → plan            0 agent turns
  2 else planner: perception pack + catalog → submit_plan → plan            1 turn
  3 compile (dm/compile.php, dm/select.php): errors? → one repair turn      +1
  4 execute (dm/exec.php): paced sequences, tesmark brackets
  5 verify (dm/verify.php): one tesprobe per step
  6 journal (dm/undo.php), outcome JSON
  ▼
Narrator Instruction with counts and names (existing tesAgentNarratorSay)   1 Narrator call
```

| Wish kind | Agent turns today | Agent turns after |
|---|---|---|
| Dice, skill check said by the player | none (invented) | 0, result inside the Narrator's own turn |
| Recipe hit: «бог воровства», «собери всех …», «раздай всем …» | 24-64 calls, turns unknown | 0 |
| Planned wish, single or bulk | 6-64 calls | 1 |
| Plan with a compile error | n/a | 2 |
| Exploring wish (`explore`) | up to 60 turns (F12) | ≤ 6 (loop) |
| Hard cap | 60 turns | 3 (plan), 6 (loop) |

Estimated planner prompt: rules 350 tokens + catalog about 1700 (68 ops) + perception pack ≤ 500 + goal; output ≤ 500. At the
measured $0.00024 per call the planned wish costs about $0.0005-0.001 against today's $0.0040-0.0053. [not verified:
no model call made]

### 6.2 Perception pack (0 turns)

`dm/perception.php` builds, from the goal text and the DB, what the model used to fetch call by call:
- people named in the goal: exact name → `core_npc_master` exact, then `tes_game_index` exact `name_lc`, then the
  nearby list with the existing heard-name rule (`tesWorldHeardName`). **No edit-distance match against the whole
  table** (F5). Each: name, known/unknown to CHIM, alive, child, jailed, relation.
- the player's name always maps to `player` (F5, «Шаман»).
- place names in the goal → cell EditorID, actor count, an anchor ref.
- who is near (last `infonpc` event, as `tesWorldGroup` reads it), current hold, interior or exterior.
- the player's sheet when a check or a "make me …" wish is present (cached `tesstate`, §7.1).

### 6.3 Selectors (`dm/select.php`)

A selector is JSON that resolves to a list of targets `{ref, name, known, child, dead, jailed, sex, source}`.

Actors:
`"player"` · `{"name":"Лидия"}` · `{"names":[…]}` · `{"near":true,"radius":3000}` · `{"in":"Вайтран"}` ·
`{"in":"here"}` · `{"cross":true}` · `{"step":"s1"}` (targets that succeeded in step s1) · `{"spawned":"s2"}`.
Filters, all optional: `sex` (male|female), `alive` (default true), `adult` (default: both; forced true for harm
classes), `role` and `role_not` (guard|merchant|court|follower), `faction`, `race`, `relation` `{min,max}`, `jailed`, `not` (names),
`limit`, `order` (nearest|random).

Resolution order:
1. `near`, `in: here`, `cross`: one `tesscan` round trip (bridge v17). On bridge < 17: the last `infonpc` event joined
   with `core_npc_master`, as `tesWorldGroup` does, and `cross` is refused with a reason.
2. `in: <place>`: `tes_game_index` actors whose cell EditorID starts with the place's base name (the rule the guard
   already uses for `coc`, `functions.php:1229-1252`); after index enrichment: actors whose cell location descends
   from the named location. Rows with an empty name are dropped (90 of Whiterun's 181 are unnamed).
3. Filters `sex`, `race`, `faction`, `role`: `core_npc_master` for known people, enriched index for the rest. Before
   enrichment a filter the index cannot answer is applied to known people only, and the outcome says how many were
   skipped as "unknown sex".
4. Child flag: `core_npc_master.race`, else index race flag (after enrichment), else the `C` flag of `tesscan` or
   `tesprobe flags`. Unknown counts as **child** for harm classes: the target is skipped and named.

Items: `{"name":"…"}` (the guard's `tesGodGuardResolveItem`) · `{"formid":"…"}` (must exist in the index) ·
`{"best":{"type":"armor","slot":"body","armor_class":"heavy","sort":"armor_rating"}}` (the query `tesAgentFind`
builds, top 1 after the cursed-item filter) · `{"kit":"dragon_fighter"}` (a list of `best` queries in
`dm/catalog.json`) · `"gold"`.

Places: `{"place":"Гарцующая кобыла"}` → cell EditorID plus an anchor ref (first named, placed actor of that cell in
the index, else a door ref from `tesscan cont` when the player is there) · `"player"` · `{"npc":"…"}` ·
`{"marker":"court"}` (`tes_watch.court_ref`).

### 6.4 Plan and compiler (`dm/compile.php`)

The compiler is pure: `tesDmCompile(array $plan, callable $resolve, int $bridgeVersion): array` returns
`['steps' => […], 'errors' => […]]`. No DB writes, no queue.

For each step it: checks the op exists and the bridge is new enough (else uses the op's `legacy` template, else
errors); checks parameters against the op's schema; resolves selectors; removes children, the player and the dead as
the op's class demands and records them as `skipped`; applies `max_targets` (overflow recorded as `over_cap`);
renders the command templates. Errors carry `{step, field, error, suggestions}`; `suggestions` reuse
`tesGodGuardSuggestNames`.

Op classes and the child rule:

| Class | Examples | Children |
|---|---|---|
| harm, strip, jail, combat, sex | kill, damage, strip, take_all, jail, fight, spawn hostile near | never a target, never an opponent |
| state | setav, essential, race, scale | skipped unless the op lists `"child":"allow"` (heal does) |
| move, give, social, info, world | gather, home, give, relation, remember, scan | allowed |

### 6.5 Executor (`dm/exec.php`)

- Transport is an interface: `send(array $commands): string $tag` and `reports(string $tag, float $timeout): array`.
  Real: `tesWorldQueue` + `tes_god_console_log`. Test: `tools/fake_game.php`. Dry runs use the fake. **A test never
  writes to `skyrim_quest_action_outbox`**: a row left there runs when the game starts.
- Every sequence is `tesmark <tag> begin`, commands, `tesmark <tag> end`. The reports between the two marker rows
  belong to this sequence; everything else is ignored. This fixes F4. It works on bridge 15 and 16 too: an unknown
  `tes*` command is still logged with its text by `TESRunAndReport` (`psc:562-569`), so the markers appear. [read; not verified in game]
- Window 1: the next sequence goes out after the previous `end` marker or 25 s. Before each send:
  `tesWorldQueueBusy()`; when busy wait up to 30 s, then stop with `status = unverified`.
- A sequence holds ≤ 10 commands. Bulk on bridge 17: `tesfor`/`tesgather` with ≤ 20 refs per command, ≤ 4 such commands
  per sequence. Bulk on bridge < 17: `prid` + command pairs, 5 targets per sequence.
- Server-side ops (relation, remember, marry, jail, fine, document, rumor, title) call `tesGodGuardRunServer` and
  re-read the row.
- The only model-written console text is op `console`; it goes through `tesGodGuardValidate` as today.
- `tesGodAutosaveIfNeeded` before the first step of class harm, quest, or a spawn of 3+; `tessave <tag>` (bridge 17)
  before ops whose undo is `none`.
- A step with `"await":"arrive"` polls `tesworld` until the cell changes (≤ 40 s) before the next step (row 28).

### 6.6 Verification and the honest report (`dm/verify.php`)

Each op declares a probe and a predicate. After the step's sequences, one `tesprobe` covers all its targets; the
answer carries each ref's own id, so a wrong selection cannot pass as success. On bridge < 17 the probe is the
`prid` + `getdead` / `getdistance` / `tesstate` set `verify.php` already uses, inside marker brackets.

Per target: `done` · `failed:<reason>` · `skipped:child|dead|jailed|player|over_cap|unknown` ·
`unverified` (no answer: pause, menu, loading). Per task: `done` (all done) · `partial` · `failed` · `unverified` ·
`impossible` (compile).

The Narrator receives a server-rendered Russian fact line from the op's `say_ru` templates, for example:
«Собраны 31 из 40: Гарцующая кобыла. Не вышло: Карлотта Валентия (в темнице), Тонаркал (не найден). Детей не трогал: 2.
Ещё 43 жителя сверх предела.» with the instruction (English) "Tell the player this outcome in your own voice, 1-2
sentences, Russian, do not claim more than the facts." A task with `unverified` targets says so: «не знаю, вышло ли».

### 6.7 Safety: three layers

1. Compile: class rule above; unknown age counts as child for harm classes.
2. Queue: `tesChildSafeCommands` on every sequence (`tesWorldQueue` already does it). Part 8 extends it to parse
   `tesfor`, `tesfight`, `tesgather` ref lists and to ask `dm/select.php` for the child flag, so the index and scan
   knowledge count, not only `core_npc_master`.
3. Bridge v17: `tesfor` refuses harmful commands for `IsChild()` actors and reports them under `child=`; `tesfight`
   drops child refs from both sides; the existing `teskill`, `tesgive`, `tesjailbox in`, `tesswapworn`, `tesduel`,
   `tesessential 0` refuse a child at their first line. The engine's own flag is the last word.

The planner prompt carries one sentence on children, with no exception list (F9 removed).

### 6.8 Undo (`dm/undo.php`)

Each op declares one undo kind:

| Kind | Meaning | Examples |
|---|---|---|
| `inverse` | fixed inverse command | give ↔ take, addperk ↔ removeperk, follow ↔ unfollow, gather → `teshome` |
| `restore` | read before, write back | setav (`tesprobe av:`), weather and hour (`tesworld`), relation and character fields (row copy), race, item name |
| `own` | remove what this task made | spawn → `tesdespawn <tag>`, memory and rumour rows by id, document, standing rule |
| `none` | cannot be undone | quest stage, killing an actor that gets deleted, `tesbecome` |

`before` is captured by a pre-probe in the same marker bracket as the write. Ops of kind `none` need `tessave` plus a
spoken confirmation («точно?») unless the recipe sets `"confirm": false`; the outcome names the save.

«Верни как было» keeps its entry point (`tesRealmUndo`, `realm.php:385-409`): the last task. New: «отмени всё за
последние N минут» undoes every task in the window, newest first; «верни <имя>» undoes journal rows of one target.
Undo runs through the same executor and verifier, so it reports what did not come back.

Resurrect stays conditional on fresh `is_dead` (existing rule, `lib.php:207-212`).

### 6.9 Standing rules (`dm/campaign.php`, table `tes_dm_rules`)

A rule is `{when, who, condition, plan, cooldown}` evaluated by a tick with zero model calls. It replaces the agent
law patrol (9 of 10 rounds failed, `postrequest.php:191-208`) and covers rows 24, 33, 52.
- `when`: `tick` (every N s), `seen` (a matching person appears in an `infonpc` event), `location` (hold or cell changes).
- `condition`: a selector filter or a probe predicate (`worn body`, `in hold`).
- `plan`: a normal plan; it passes the same compiler, safety layers and journal.
- Limits: ≤ 10 active rules, ≤ 1 rule execution per 60 s, ≤ 3 targets per execution, nothing while `tesWorldQueueBusy()`.

A wish creates a rule with op `rule_add`; «отмени правило …» and undo remove it.

### 6.10 What stays unchanged

`goal:` and `ask:` prefixes; the guard for Narrator console text; `tes_world` spoken handlers and fast orders;
`tes_crime`; the gods; the panel's undo button; the task queue (`tesAgentStart`, one worker at a time).

---

## 7. The tabletop layer (same catalog, same executor)

### 7.1 Dice and skill checks (`dm/dice.php`)

- `roll`: server RNG (`random_int`), expression `NdM+K` with N ≤ 20, M ∈ {2,3,4,6,8,10,12,20,100}. The model never
  supplies a result. Every roll is a row in `tes_dm_rolls` and a corner notice «d20: 14».
- `check`: `total = d20 + mod(skill) + situational` against `dc`. `mod(skill) = floor((skill − 10) / 10)` clamped to
  −1…+9 (skill 15 → 0, 55 → +4, 100 → +9). The skill is the player's **real base value** from `00000014.tesstate`
  (`psc:759-832`), cached in `tes_watch` key `dm_sheet` for 120 s. No answer from the game → the check is refused with
  «игра не ответила», never rolled blind. Natural 1 fails, natural 20 succeeds.
- `dc`: number, or word `easy 8 · medium 12 · hard 16 · heroic 20`, or `"vs": {npc}`: `10 + mod(opposing skill)` from
  `core_npc_master.metadata.skills` (keys `speech`, `sneak`, `onehanded`… [ran]) or level/5 when the NPC is unknown.
- Opposed contest (row 47): both sides roll; ties re-roll once.
- Attribute checks use Health, Magicka, Stamina base /10 as the modifier source ("сила" → Stamina, "воля" → Magicka).
- A check step has `on_success` and `on_fail` step lists; the executor runs one branch. Row 46: success → `unlock` on
  the crosshair ref.
- Player-initiated checks are recipes with `"pre": true`: matched in `ext/tes_agent/preprocessing.php` before the
  Narrator's turn; the result is appended to his line the way the gods do it (`*проверка Красноречия: d20 14 + 7 = 21
  против 15: успех*`), so the Narrator narrates a real result in its own turn. Skill words map through a data table
  in `dm/recipes.json` (уговор/убед → Speechcraft, взлом/вскр → Lockpicking, крад/карман → Pickpocket, подкрад → Sneak…).
- Narrator-initiated checks (row 48): God_Command target `check: <Skill> <dc|word> [vs <name>] | <what is at stake>`
  or `roll: 2d6`. Handled synchronously in `ext/tes_agent/functions.php`, no worker; the result returns through
  `tesAgentNarratorSay`.

### 7.2 Encounters (`dm/encounters.json`)

An encounter is data: groups `{who: creature selector, n: [min,max], dist, angle, stance, level}` plus optional
`hostile_to`. Ops `spawn_group` (bridge `tesspawn`) and `fight` (`tesfight`) execute it. Count scales with the
player's level from the sheet. The spawned refs return in the report, so the encounter is verified (alive count by
`tesprobe life`), journalled as `own`, and removable with one `tesdespawn`. Caps: ≤ 10 actors per group, ≤ 20 per
wish, no hostile spawn within 1500 units of a known child (compile-time, from the scan).
Rows 39, 40, 56 are three entries of this file plus recipes.

### 7.3 Campaign memory (`dm/campaign.php`, table `tes_dm_campaign`)

Rows of kind `plot` (title, state, next beat, deadline), `fact` (a renamed place, a promise, a debt), `party`
(members), `deed` (what the DM did, written by the executor from the outcome). Ops `plot_open`, `plot_advance`,
`plot_close`, `fact_set`, `fact_forget` write it; no summarising model call exists.

`ext/tes_agent/context_pre.php` injects one line into Narrator requests only, ≤ 600 characters: up to 3 open plots,
active standing rules, the last 3 deeds. It replaces part of today's 1.1 KB instruction line, so the Narrator prompt
does not grow.

A "quest" from a wish (row 36) = `plot_open` + `write_document` (the task as a letter) + `spawn_group` or `gather` at
an existing place + a standing rule that closes the plot when its condition holds (target dead, item in the player's
inventory, player in the cell). No journal entry: said to the owner as a limit.

Rollback with a save: every new table carries `gamets bigint` and is listed in
`ext/tes_world/playthrough_tables.txt`. Pruning "the future" on load is ROADMAP item 13 (design P5); this design only
supplies the column.

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
        "who": {"description": "Actor selector: \"player\", {\"name\":…}, {\"in\":…}, {\"near\":true}, {\"step\":\"s1\"} with filters."},
        "to": {"description": "Place or actor selector, for ops that move or give."},
        "args": {"type": "object", "description": "Op parameters as the catalog lists them."},
        "limit": {"type": "integer"},
        "await": {"type": "string", "enum": ["arrive"]},
        "on_success": {"type": "array", "items": {"type": "string"}},
        "on_fail": {"type": "array", "items": {"type": "string"}}
      }}}
  }
}
```

Example, row 21:
```json
{"say":"Созываю Вайтран.","steps":[
 {"id":"s1","op":"gather","who":{"in":"Вайтран","role_not":["guard"],"alive":true},"to":{"place":"Гарцующая кобыла"}}]}
```
Example, row 18:
```json
{"steps":[
 {"id":"s1","op":"heal","who":{"name":"Лидия"}},
 {"id":"s2","op":"give","who":{"name":"Лидия"},"args":{"items":[{"kit":"dragon_fighter"}],"equip":true}},
 {"id":"s3","op":"set_skills","who":{"name":"Лидия"},"args":{"values":{"TwoHanded":100,"HeavyArmor":100,"Block":100},"raise_only":true}}]}
```

### 8.2 Planner system prompt (English, exact)

```
You plan one wish of the player for the game master of a Skyrim SE playthrough (Requiem/RFAD, Russian game data).
Answer with exactly one submit_plan call.
- Use only ops from the catalog below. One step can act on a whole set of people: use a selector, never list people one by one.
- You never write IDs. Name people, places and things in Russian as the player said them; the server finds them.
- Do exactly what was asked, nothing more. Move the player only when he asked to be moved.
- Children are never harmed, stripped, jailed, or put in a fight. Do not plan it; the server removes them anyway.
- "Best" gear: use {"best":{...}} or a kit; do not search.
- A question about the world: one step with op "answer" and the selectors that hold the facts.
- If the catalog cannot do the wish, set "impossible" to the reason and steps to [].
- Every text the player hears or reads (say, documents, rumours, memories) is Russian.
Catalog: <one line per op: name, params, one-sentence description>
Facts: <perception pack>
```
Repair turn, user message: `Your plan did not compile. Fix only these errors and submit again: <errors JSON>`.

### 8.3 Catalog entry (`ext/tes_agent/dm/catalog.json`)

```json
{"op": "gather",
 "desc": "Bring people to a place or to the player.",
 "params": {"who": "actors", "to": "place"},
 "class": "move", "max_targets": 40, "min_bridge": 17,
 "run":    {"bulk": "tesgather {tag} {to.dec} 300 {refs.dec}"},
 "legacy": {"each": ["prid {ref.hex}", "moveto {to.hex_or_player}"]},
 "verify": {"probe": "near:{to.dec}", "ok": "0 <= v && v <= 2500", "delay_s": 3},
 "undo":   {"kind": "inverse", "bulk": "tesfor {tag} {refs.dec} :: teshome"},
 "say_ru": {"done": "Собраны {ok}: {to.name}.", "partial": "Собраны {ok} из {n}: {to.name}.", "failed": "Собрать не вышло."}}
```
Fields: `run.bulk` (one command for many refs) or `run.each` (per target, rendered into `tesfor` when every line is
a plain console command and the bridge is ≥ 17, else into `prid` pairs) or `run.server` (a `tesGodGuardRunServer`
verb) or `run.native` (`director`, `create_npc`, `spawn_template`). `verify.ok` is evaluated by a 20-line expression
checker over `v` (number or letter); no `eval`.

First catalog, 68 ops. s = works on bridge 15/16, (17) = needs bridge v17.

| Group | Ops |
|---|---|
| people, state | `heal` s · `revive` s · `kill` s · `essential` s · `set_skills` s · `set_av` s · `add_perks` s · `add_spell` s · `scale` s · `race` (17) · `idle` s · `pacify` s · `ally` s |
| people, place | `gather` (17, legacy s) · `home` (17) · `settle` (17 for `self`, s for `here`) · `follow` s · `unfollow` s · `jail` s · `release` s · `fine` s |
| people, mind | `relation` s · `remember` s · `forget` s · `character` s · `marry` s · `order` s · `scene` s (native Director) |
| things | `give` s · `take` s · `take_all` s · `strip` s · `dress` s · `enchant` (17) · `item_rename` (17) · `unlock` s · `claim_here` s |
| world | `weather` s · `hour` s · `skip_time` (17) · `teleport` s · `portal` s (ScriptProxy `SpawnDoor`) · `rumor` s · `document` s · `title` s |
| creatures | `spawn_group` (17, legacy s without offset) · `despawn` (17) · `fight` (17) · `stop_fight` s · `create_npc` s (native) |
| player | `level` s · `perk_points` s · `teach_word` s (index) · `become` (17) |
| quests | `quest_stage` s (undo `none`, confirm) · `quest_read` s |
| table | `roll` · `check` · `plot_open` · `plot_advance` · `plot_close` · `fact_set` · `fact_forget` · `rule_add` · `rule_remove` |
| info | `scan` · `answer` · `console` (guarded raw text) |

### 8.4 Recipes (`ext/tes_agent/dm/recipes.json`)

```json
{"id": "gather_place",
 "match": ["(?:собер\\p{L}*|созов\\p{L}*|сгон\\p{L}*)\\s+(?:всех\\s+)?(?<who>жител\\p{L}*|люд\\p{L}*|женщин\\p{L}*|мужчин\\p{L}*|страж\\p{L}*)\\s+(?<city>\\p{Lu}[\\p{L}-]+)\\s+в\\s+(?<place>.+)$"],
 "slots": {"who": "people_word", "city": "place_name", "place": "place_name"},
 "pre": false, "confirm": false,
 "plan": {"steps": [{"id": "s1", "op": "gather", "who": {"in": "{city}", "$people_word": "{who}"}, "to": {"place": "{place}"}}]}}
```
Slot types (`people_word`, `place_name`, `npc_name`, `number`, `skill_word`, `craft_word`) are small Russian tables in
the same file. A recipe that fails to fill a slot or to compile falls through to the planner. First set: 24 recipes
covering rows 5, 9, 11, 18, 21-23, 25, 26, 31, 39, 40, 44-47, 49, 52, 57, 58, 60 and the "бог <ремесла>" family.

### 8.5 Tables

```sql
-- existing, new columns
ALTER TABLE tes_agent_tasks ADD COLUMN IF NOT EXISTS mode text NOT NULL DEFAULT 'loop';   -- recipe | plan | loop
ALTER TABLE tes_agent_tasks ADD COLUMN IF NOT EXISTS turns int NOT NULL DEFAULT 0;        -- model turns, not calls
ALTER TABLE tes_agent_tasks ADD COLUMN IF NOT EXISTS plan jsonb;
ALTER TABLE tes_agent_tasks ADD COLUMN IF NOT EXISTS outcome jsonb;                       -- per step: counts and names
ALTER TABLE tes_agent_tasks ADD COLUMN IF NOT EXISTS gamets bigint;

CREATE TABLE IF NOT EXISTS tes_dm_steps (
  id bigserial PRIMARY KEY, task_id bigint NOT NULL, step_id text NOT NULL, op text NOT NULL,
  target_ref text NOT NULL DEFAULT '', target_name text NOT NULL DEFAULT '',
  status text NOT NULL,            -- done | failed | skipped | unverified
  detail text NOT NULL DEFAULT '', before jsonb, tag text NOT NULL DEFAULT '',
  created_at timestamptz NOT NULL DEFAULT now(), gamets bigint);

CREATE TABLE IF NOT EXISTS tes_agent_undo (            -- lib.php:164 shape plus four columns
  id bigserial PRIMARY KEY, task_id bigint NOT NULL, command text NOT NULL, inverse text,
  undone boolean NOT NULL DEFAULT false, created_at timestamptz NOT NULL DEFAULT now(),
  step_id text NOT NULL DEFAULT '', target_ref text NOT NULL DEFAULT '',
  kind text NOT NULL DEFAULT 'inverse', before jsonb);

CREATE TABLE IF NOT EXISTS tes_dm_rolls (
  id bigserial PRIMARY KEY, created_at timestamptz NOT NULL DEFAULT now(), gamets bigint, task_id bigint,
  kind text NOT NULL,              -- roll | check | contest
  expr text NOT NULL, dice jsonb NOT NULL, modifier int NOT NULL DEFAULT 0, dc int, total int NOT NULL,
  outcome text NOT NULL DEFAULT '',-- success | fail | crit | fumble | ''
  who text NOT NULL DEFAULT 'player', skill text NOT NULL DEFAULT '', stake text NOT NULL DEFAULT '');

CREATE TABLE IF NOT EXISTS tes_dm_campaign (
  id bigserial PRIMARY KEY, kind text NOT NULL,        -- plot | fact | party | deed
  title text NOT NULL, body text NOT NULL DEFAULT '', state text NOT NULL DEFAULT 'open',
  data jsonb NOT NULL DEFAULT '{}', task_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), gamets bigint);

CREATE TABLE IF NOT EXISTS tes_dm_rules (
  id bigserial PRIMARY KEY, title text NOT NULL, when_kind text NOT NULL,   -- tick | seen | location
  who jsonb NOT NULL, condition jsonb NOT NULL DEFAULT '{}', plan jsonb NOT NULL,
  cooldown_s int NOT NULL DEFAULT 600, active boolean NOT NULL DEFAULT true,
  last_run timestamptz, runs int NOT NULL DEFAULT 0, task_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(), gamets bigint);
```
All five go into `ext/tes_world/playthrough_tables.txt`. Tables are created by the plugin on first use
(`CREATE TABLE IF NOT EXISTS`), as every `tes_*` table is.

### 8.6 Bridge v17: the exact list

One recompile, one game restart. FormIDs in arguments are **signed 32-bit decimal** (as `tesduel` and `teslove` take
them); lists are comma-separated without spaces, ≤ 20 refs. Reports are `command@@output` through
`AIAgentFunctions.logMessage(…, "tes_god_console")`, each ≤ 450 characters; longer answers are split into chunks
`<i>/<n>`.

| # | Command | Does | Report output |
|---|---|---|---|
| 1 | `tesversion` | | `17` |
| 2 | `tesmark <tag> begin` / `end` | nothing; brackets a sequence | `ok` |
| 3 | `tesfor <tag> <refs> :: <command>` | for each ref: `Game.GetForm`, `ConsoleUtil.SetSelectedReference`, run `<command>` (a `tes*` command through the bridge dispatcher, anything else through `ConsoleUtil.ExecuteCommand`). Harmful commands are not run for `IsChild()` actors. No `prid`. | `ok=<n> missing=<refs> child=<refs>` |
| 4 | `tesprobe <tag> <what> <refs>` | reads one fact per ref. `what`: `life` (a alive, d dead, x disabled, m missing) · `near:<ref>` (distance, −1 other cell) · `worn` (bit mask head 1, body 2, hands 4, feet 8) · `item:<form>` · `gold` · `av:<Name>` (base) · `fac:<form>` (rank, −2 none) · `flags` | chunks `<ref>=<value>;…` |
| 5 | `tesscan <tag> near <radius>` · `cell` · `cross` · `cont <radius>` | lists actors (or the crosshair ref, or containers and doors). Flags: M F sex, C child, D dead, G guard, H hostile to the player, K in combat, T teammate, E essential, U unique, A not a person | chunks `<ref>:<flags>:<hp%>:<dist>:<name>\|…`, then `tesscan <tag> end@@count=<N>` |
| 6 | `tesgather <tag> <dest ref or 0 = player> <radius> <refs>` | `MoveTo` on a ring of `<radius>` around the destination, `EvaluatePackage`; the dead are skipped | `ok=<n> missing=<refs> dead=<refs>` |
| 7 | `teshome` (selected) | `MoveToMyEditorLocation`, removes the routine override, `EvaluatePackage` | `<name> sent home` |
| 8 | `tesroutine self` (new mode of an existing command) | the routine marker goes where the selected actor stands | as `tesroutine here` |
| 9 | `tesspawn <tag> <base> <count ≤ 10> <dist> <angle> <stance>` | `PlaceActorAtMe` at the player, moved `<dist>` units at `<angle>` from his heading. Stance 0 neutral, 1 hostile to the player, 2 ally, 3 calm. Refs stored under the tag. | `refs=<refs>` |
| 10 | `tesdespawn <tag>` | disables and deletes only refs stored under the tag | `removed=<n>` |
| 11 | `tesfight <tag> <refsA> \| <refsB>` (`14` = the player) | `A[i].StartCombat(B[i mod nB])` and back; child refs dropped from both lists | `pairs=<n> child=<refs> missing=<refs>` |
| 12 | `testime <hours ≤ 720>` | advances game time | `days=<GameDaysPassed> hour=<GameHour>` |
| 13 | `tesworld` | world state in one line | `hour=<f> days=<f> weather=<form> cell=<form> interior=<0\|1> combat=<0\|1> level=<n> loc=<name>` |
| 14 | `tesrace <race form or 0>` (selected, never a child) | `SetRace`; the first original race is stored; 0 restores it | `<name>: <old> -> <new>` |
| 15 | `tesitem <hand: 0 left, 1 right> <temper or 0> <new name or ->` (selected actor) | `WornObject.SetDisplayName`, `SetItemHealthPercent`; old values stored | `<old name> -> <new name> temper <f>` |
| 16 | `tesenchant <hand> <charge> <mgef>:<mag>:<dur>[,…]` (selected actor) | `WornObject.CreateEnchantment` | `<item>: enchanted <n>` or `error: …` |
| 17 | `tesbecome vampire` · `werewolf` · `cure` | calls the vanilla quest scripts on the player | `done` or `error: …` |
| 18 | `tessave <tag>` | `Game.SaveGame("TES_before_" + tag)` | `TES_before_<tag>` |

Hardening in the same version: `teskill`, `tesgive`, `tesjailbox in`, `tesswapworn`, `tesduel` (the condemned),
`tesessential 0` refuse an `IsChild()` actor at their first line and report `error: refused (a child)`.

`ext/tes_agent/dm/bridge.php` holds the report parsers and the command builders; the fake game and the real bridge
both follow this table.

---

## 9. Parts for builders

While the worktree `/home/dwemer/scratch/tes_en` (branch `en-prompts`) is open nobody edits existing files under
`ext/` (`PLAN-2026-10-09.md`). Parts 1-7 create **new files only**. Part 8 is the one that edits existing files and
waits for that merge. No part calls a model in its acceptance check. No part writes to `skyrim_quest_action_outbox`
in a test.

Order: 1 → 2 → 3 → 5 → 8. Parts 4, 6, 7 have no code dependency and can start at once.

### Part 1: catalog and compiler
- Owns: `ext/tes_agent/dm/catalog.json`, `ext/tes_agent/dm/compile.php`, `ext/tes_agent/dm/bridge.php`,
  `tools/test_dm_compile.php`, `tools/fixtures/dm_compile/*.json`.
- Produces: the 68 ops of §8.3 as data; `tesDmCompile(array $plan, callable $resolve, int $bridgeVersion): array`;
  `tesDmCatalogLines(): string` (the prompt lines, English); builders and parsers for every command of §8.6.
- Contract in: plan of §8.1; `$resolve(string $kind, mixed $selector): array` returns targets
  `{ref, name, child, dead, jailed, known}` or `['error' => …, 'suggestions' => […]]`.
- Contract out: `['steps' => [['id','op','class','targets' => […],'skipped' => […],'sequences' => [[cmd,…],…],
  'probe' => …, 'undo' => …]], 'errors' => [['step','field','error','suggestions']]]`.
- Acceptance: `php tools/test_dm_compile.php` prints ALL OK. It checks with a stub resolver: (a) a plan with an
  unknown op, a missing parameter and a bad selector yields three errors and zero sequences; (b) `kill` on a list
  with a child, an unknown-age target and the player yields none of the three in any rendered command; (c) `gather`
  of 45 targets at bridge 17 gives 2 sequences, ≤ 20 refs per `tesgather`, 5 marked `over_cap`; at bridge 15 it gives
  `prid` pairs, ≤ 10 commands per sequence; (d) every catalog entry validates against the entry schema and every op
  of class harm has a `verify` and an `undo`; (e) round trip: every §8.6 report example parses.
- Depends on: nothing.

### Part 2: selectors and perception
- Owns: `ext/tes_agent/dm/select.php`, `ext/tes_agent/dm/perception.php`, `tools/test_dm_select.php`.
- Produces: `tesDmResolve(string $kind, $selector, ?array $scan = null): array` (the `$resolve` of part 1);
  `tesDmPerception(string $goal): array`; `tesDmIsChildRef(string $ref, ?array $scan = null): ?bool` (null = unknown).
- Contract: SELECT only on `tes_game_index`, `core_npc_master`, `eventlog`, `tes_crime_jail`, `tes_watch`. `$scan` is
  a parsed `tesscan` (from `bridge.php`); without it `near` and `here` use the last `infonpc` event.
- Acceptance: `php tools/test_dm_select.php` against the live DB, read-only, prints ALL OK: `{"in":"Вайтран"}`
  returns ≥ 60 named actors including ref 0001A66E (Хульда) and no row with an empty name; `{"name":"Хельга"}` never
  returns Хульда; `{"name":"Ингун Черный Вереск"}` never returns Скульвар; the player's name returns `player`;
  `{"name":"Мила Валентия"}` has `child = true`; `{"in":"Вайтран","sex":"female"}` returns only women and reports a
  count of "unknown sex" skipped; `{"best":{"type":"armor","slot":"body","armor_class":"heavy"}}` returns one FormID
  that exists in the index with `ar` equal to the maximum of that filter; `{"place":"Гарцующая кобыла"}` returns cell
  `WhiterunBanneredMare` and an anchor ref placed in it.
- Depends on: part 1 (target shape).

### Part 3: executor, verification, undo, fake game
- Owns: `ext/tes_agent/dm/exec.php`, `ext/tes_agent/dm/verify.php`, `ext/tes_agent/dm/undo.php`,
  `tools/fake_game.php`, `tools/test_dm_exec.php`.
- Produces: `tesDmRun(int $taskId, array $compiled, TesDmTransport $t): array` (the outcome JSON);
  `tesDmUndo(array $filter, TesDmTransport $t): array` with filter `{last: true}` | `{minutes: N}` | `{target: ref}`;
  the transport interface with two implementations (real: `tesWorldQueue` + `tes_god_console_log`; fake);
  `tesDmSayRu(array $outcome): string`.
- Contract: sequence = `tesmark` brackets, ≤ 10 commands, window 1, 25 s timeout, `tesWorldQueueBusy()` gate; one
  probe per step; journal row per target with `before`; statuses of §6.6.
- Acceptance: `php tools/test_dm_exec.php` prints ALL OK, using the fake only and a scratch schema or temp tables for
  `tes_dm_steps` and `tes_agent_undo` (no row survives the test). Cases: (a) gather 40 with 2 children, 3 missing,
  1 jailed → outcome counts exact, Russian line names the jailed one; (b) strip 6 with one child in the list → the
  fake's received log holds no command aimed at the child; (c) the fake emits a foreign report outside the
  markers → it is ignored; (d) the fake goes silent → status `unverified`, never `done`; (e) `set_av` then
  undo → the fake's value equals the value before; (f) `weather` + `hour` then undo → restored; (g) kill then undo
  with the target alive in the fake → no `resurrect` is sent; (h) undo `{minutes: 5}` over three tasks runs newest
  first.
- Depends on: parts 1, 2.

### Part 4: bridge v17
- Owns: `papyrus/TESGodConsoleReport/Source/Scripts/AIAgentQuestProgressionBridge.psc` (additions only),
  `papyrus/TESGodConsoleReport/README.md` (the v17 section), `tools/test_bridge_contract.php`,
  `docs/in-game-tests.md` (a v17 section of 8 console lines).
- Produces: the 18 rows of §8.6 and the six hardened commands; the previous `.pex` kept as `.pex.bak-v16`.
- Contract: §8.6 exactly: names, argument order, signed decimal ids, report text.
- Acceptance without the game: (a) `PapyrusCompiler.exe` exits 0 with the import list of the toolchain note;
  (b) `php tools/test_bridge_contract.php` prints ALL OK: each command name of §8.6 is dispatched in
  `TESRunAndReport`; `tesversion@@17` is present; each of the six hardened handlers and `tesfor`, `tesfight`,
  `tesrace` contains `IsChild()` before its first state-changing call; every `logMessage` literal of the new commands
  parses with `dm/bridge.php`. In-game checks stay with the owner.
- Depends on: nothing (contract from this file). Deploy needs the owner: a game restart.

### Part 5: planner and recipes
- Owns: `ext/tes_agent/dm/planner.php`, `ext/tes_agent/dm/recipes.json`, `ext/tes_agent/dm/kits.json`,
  `tools/test_dm_plan.php`, `tools/fixtures/dm_plan/*.json`.
- Produces: `tesDmRecipe(string $goal): ?array` (plan or null); `tesDmPlan(string $goal, array $perception,
  callable $llm): array` with ≤ 3 turns; the prompt of §8.2; 24 recipes; kits `thief`, `alchemist`, `smith`, `archer`,
  `mage`, `dragon_fighter`.
- Contract: `$llm(array $messages, array $tools): ?array` is injected (the worker passes `tesAgentLlm`); the planner
  offers one tool, `submit_plan`; a reply without a valid call counts as a turn.
- Acceptance: `php tools/test_dm_plan.php` prints ALL OK with a stub `$llm` that replays fixtures: (a) each of the 24
  recipes matches its 3 sample phrases (Russian, with the speech-to-text noise found in the logs: no punctuation,
  lower case) and compiles; none matches the 20 negative phrases taken from real non-wish lines; (b) a fixture with a
  compile error leads to exactly one repair turn; (c) a third bad reply ends with `impossible`, zero game commands;
  (d) the rendered prompt is ≤ 12000 characters and holds no Cyrillic outside the Facts block.
- A paid evaluation of the real model on the 60 wishes (about 60 calls, estimated ≤ $0.06) needs the owner's yes
  (ROADMAP rule 6) and is not part of acceptance.
- Depends on: parts 1, 2.

### Part 6: tabletop layer
- Owns: `ext/tes_agent/dm/dice.php`, `ext/tes_agent/dm/campaign.php`, `ext/tes_agent/dm/encounters.json`,
  `tools/test_dm_table.php`.
- Produces: `tesDmRoll(string $expr, ?callable $rng = null): array`; `tesDmCheck(array $spec, array $sheet,
  ?callable $rng = null): array`; `tesDmSheet(?string $tesstateLine = null): ?array`; `tesDmCampaignLine(): string`;
  `tesDmRulesDue(array $event): array`; 8 encounters (bandits, archers ambush, wolves, undead, dragon, caravan
  attack, tavern brawl, assassins).
- Contract: §7; tables `tes_dm_rolls`, `tes_dm_campaign`, `tes_dm_rules` of §8.5.
- Acceptance: `php tools/test_dm_table.php` prints ALL OK: seeded `$rng` gives fixed totals; `3d6+2` stays in 5-20
  over 10000 rolls and its mean is 12.5 ± 0.2; `mod` for skills 5, 15, 55, 100 is −1, 0, 4, 9; a real `tesstate` line
  copied from task 201 step 8 parses to `Speechcraft` and `level 252`; a null sheet makes `tesDmCheck` return
  `refused`; natural 1 fails against dc 2; the campaign line is ≤ 600 characters with 5 plots and 12 deeds stored;
  a `seen` rule fires once for a matching `infonpc` sample and respects its cooldown.
- Depends on: part 1 for op names only.

### Part 7: index enrichment
- Owns: `tools/game_index.py`, `tools/test_index_enrich.py`.
- Produces: `npc.extra` gains `sex`, `race` (FormID), `child` (from the RACE record's child flag), `unique`,
  `essential`, `fac` (faction FormIDs); `cell.extra` gains `loc`; `location.extra` gains `parent`; new kinds `race`,
  `shout`, `word`.
- Contract: the TSV format of the file header is unchanged; new keys only inside `extra_json`.
- Acceptance: `python tools/test_index_enrich.py <out.tsv>` prints ALL OK on a TSV built on the owner's Windows
  Python: `HousecarlWhiterun` has `sex = F` and `unique`; the base of Люсия and of Мила Валентия has `child`;
  cell `WhiterunBanneredMare` has `loc` = the FormID of `WhiterunBanneredMareLocation`, whose `parent` chain reaches
  `WhiterunLocation`; ≥ 20 rows of kind `shout`; row counts of the old kinds differ from the current index by < 1 %.
  Loading the TSV into `tes_game_index` changes data: owner's yes.
- Depends on: nothing. Parts 2 and 5 work without it, with the limits §6.3 states.

### Part 8: integration, safety net, benchmark gate
- Owns the edits to existing files: `ext/tes_agent/worker.php` (plan path by default; the loop behind `--loop`,
  `TES_AGENT_MAX_STEPS` 6; `$turns` saved; F9 sentence removed), `ext/tes_agent/lib.php` (columns, `mode`),
  `ext/tes_agent/functions.php` (`roll:` and `check:` prefixes), `ext/tes_agent/context_pre.php` (English line with
  the campaign line), `ext/tes_world/childsafe.php` (ref lists of `tesfor`, `tesfight`, `tesgather`;
  `tesDmIsChildRef`), `ext/tes_world/realm.php` (undo window phrases), `ext/tes_world/postrequest.php` (agent patrol
  branch removed in favour of rules), `ext/tes_world/playthrough_tables.txt`, `ext/tes_world/panel.php` (rolls, rules,
  per-step outcome). New: `ext/tes_agent/preprocessing.php` (tick for rules, `pre` recipes),
  `tools/test_dm_bench.php`, `tools/fixtures/dm_bench/01.json … 60.json`, `tools/deploy_tes_agent.sh` update.
- Contract: prefixes of §6.1; `tesAgentStart` signature unchanged.
- Acceptance: (a) `php tools/test_ext.php` at its baseline (97 passed, 2 old failures) and `php tools/test_court.php`
  ALL OK; (b) `php tools/test_dm_bench.php` prints one line per benchmark row and ALL OK: each of the 52 "W" rows
  compiles from its recipe or its reference plan and runs to `done` against the fake game, each "S" row ends
  `partial` or `done` with its limit in the Russian line, rows 8 and 29 name their substitute; turns per row equal
  the route's budget (0 recipe, 1 plan); no command in any row targets a child of the fake; (c)
  `php ext/tes_agent/worker.php --goal "собери всех жителей Вайтрана в Гарцующей кобыле" --dry` ends `done` with zero
  rows added to `skyrim_quest_action_outbox`.
- Depends on: parts 1, 2, 3, 5, 6; the merge of `en-prompts`.

---

## 10. Assumptions

| # | Assumption | Status | If false |
|---|---|---|---|
| A1 | The index answers "who is placed in cell X" for named actors | verified [ran]: 181 Whiterun actors, 91 named | bulk by place falls back to people CHIM knows (242) plus a scan |
| A2 | Unknown `tes*` commands are still logged with their text, so `tesmark` brackets work on bridge 15/16 | read (`psc:562-569`), not run | on old bridges correlate by echoing the expected command order; bulk waits for v17 |
| A3 | The v15 lock keeps one sequence's reports contiguous | read; the v15 game session of 10-06 is the only live evidence | add the tag to every command of the sequence via `tesfor` only; no `prid` path |
| A4 | A cheap model writes a valid plan in one turn for most non-recipe wishes | **not verified**; no model call was allowed | recipes still give 0-turn coverage of 24 wish families; the cap stays 3 turns; consider the stronger connector 12 for the planner only |
| A5 | `tesfor` may select and execute for 20 refs in one Papyrus call without `Utility.Wait` | not verified | add `Utility.Wait(0.05)` per ref: 20 refs take about 1 s more |
| A6 | A command or report of ≤ 450 characters survives the plugin transport | verified for reports ≤ 500 [ran]; commands that long not verified | cut ref lists to 10 per command |
| A7 | `MoveTo` and `Game.GetForm` reach named NPCs in unloaded cells | read (the feast and `move_npc` do it by `prid`; 51 of 4880 `prid` failed) | the outcome lists them as `missing`; nothing breaks |
| A8 | `MoveToMyEditorLocation` sends a moved NPC home | not verified | undo of `gather` becomes "they walk home by their packages" and says so |
| A9 | Advancing `GameHour` past 24 rolls the day safely with this mod list | not verified | `skip_time` stays "W?"; fallback: the player's own wait menu, said honestly |
| A10 | SKSE `WornObject.CreateEnchantment` and `SetDisplayName` work on 1.5.97 with Requiem | not verified | rows 7 and 8 stay F and X |
| A11 | The vanilla vampire and werewolf quest scripts are callable under RFAD | not verified | row 13 stays F |
| A12 | Cyrillic in a command argument (`tesitem` name) survives the outbox → Papyrus path | not verified; the core strips Cyrillic on its own path (`functions.php:845`) | the name goes by a StorageUtil string set through a transliterated key, or row 8 loses the rename |
| A13 | A console sequence continues after `coc` loads a cell | not verified | row 28: two sequences with `await` between them (already the design) |
| A14 | `tesstate` on `00000014` returns the player's base skills | verified [ran]: task 201 steps 8 and 38 | none |
| A15 | Children can be recognised before a harmful command | partly verified: 9 known to CHIM; the engine flag exists (`IsChild()` used at `psc:159, 301, 436`) | unknown age is treated as child (already the design) |
| A16 | The Narrator keeps emitting `goal:` for wishes | verified [read]: 114 agent tasks exist | none |
| A17 | Bridge 16 is installed and is the base for 17 | read (ROADMAP §4); the game last ran 15 | part 4 diffs against the v16 source in the repo, which is the file read here (`tesversion@@16`) |

---

## 11. What can go wrong in production

| Risk | How it is noticed | How it is undone |
|---|---|---|
| A bulk op hits the wrong people | `tes_dm_steps` rows whose probe answer names another ref; panel shows per-target status | `tesDmUndo {last}`; `tessave` before harm classes |
| A child receives a harmful command | impossible by three layers; a `child=` list in any `tesfor` report is logged as `[childsafe]` and shown on the panel | n/a; the bridge did not run it |
| Recipe false positive: plain talk starts a wish | task row with `mode = recipe` and a goal that reads as chat; the Narrator's line says what was done | undo; delete or tighten the recipe (data, no deploy of code) |
| Planner writes a harmful but valid plan the player did not ask for | the plan is stored in `tes_agent_tasks.plan`; harm classes over 3 targets require the wish text to contain a plural or "всех" (compiler rule), else `confirm` | undo; autosave exists |
| 40 people in one interior hurt the frame rate or crash | owner sees it; `max_targets` is data | lower the cap; `home` on the set |
| The queue backs up and sequences race again | `tesWorldQueueBusy()` true for > 30 s; `unverified` outcomes | executor stops sending; rules stop; nothing is retried blindly |
| Bridge 17 breaks every order | all `tes*` reports say "not found" or stop; `tesBridgeVersion()` ≠ 17 | rename `.pex.bak-v16` back, restart the game (ROADMAP §4 procedure) |
| Standing rules loop | `tes_dm_rules.runs` grows fast; limits of §6.9 | `UPDATE … SET active = false` from the panel button; undo of the creating task removes the rule |
| The key runs out mid-task | `tesAgentLlm` returns null: the task ends `failed` with «модель не ответила» before any game command (planning precedes execution) | none needed |
| A CHIM update changes `tesGodGuardRunServer` callers or the outbox | `tools/test_ext.php`, `tools/test_dm_exec.php` after `tools/after_update.sh` | `tools/restore_core.sh`; the plugin files are ours |
| Old save loaded: campaign rows from "the future" | plots mention events that did not happen | until ROADMAP item 13 lands: panel button "forget after game time T" using `gamets` |

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
