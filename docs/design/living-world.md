# Design 2026-10-10: the world lives while you are away, and dreams

Status: design only, nothing built. Written on the day the project was frozen (`docs/FROZEN.md`); not yet
attacked by a verifier. Read against `main` @ e170c3d; the live plugins equal the repo (`diff -rq` empty, [V]).

Marks: **[V]** I ran the query or command on 2026-10-10. **[R]** I read the code, did not run it.
**[N]** not verified. The database went down at 16:16 (+05) when the distro was restarted for the freeze;
two small checks stayed [N] for that reason and are named where they matter.

Paths: `CORE` = `/var/www/html/HerikaServer`, `REPO` = `/home/dwemer/TES-Speech-Adapter`,
`PSC` = `MO2\mods\AIAgent\Source\Scripts`.

Criteria, in the brief's order: (1) hard rules: children, scenes, languages, no core edit, no standing prompt
line above 60 tokens; (2) feature A makes zero model calls, feature B at most one per night with a zero-call
fallback; (3) deterministic given a seed, bounded, journaled, undoable, no memory of the future;
(4) outcomes the player can see; (5) reuse of what the platform does; (6) every part testable without the game
and without a model.

Language rule: text sent to a model is English (names stay Russian); text the player reads or hears is Russian.

Contents: 1 findings · 2 approaches for A · 3 feature A · 4 feature B · 5 contracts · 6 parts for builders ·
7 assumptions · 8 production risks · 9 needs the game · 10 open questions · 11 found on the way.

---

## 1. Findings that constrain the design

### Time and the events that reach the server

| # | Finding | Evidence |
|---|---|---|
| F1 | `gamets` = game days passed x 10 000 000. Game day = `intdiv(gamets, 10000000)`, one game hour = 416 667. | [V] eventlog row 85028: gamets 219 800 512, text `Сандас, 11:31 PM, 7-е Огня очага` (day 21.98 after 17 Last Seed). [R] `CORE/lib/utils_game_timestamp.php:150-166` (x 0.0000001 days, x 0.0000024 hours); `ext/tes_crime/lib.php:138` |
| F2 | A sleep event reaches the server. `goodnight` is a request type; the core logs it and ends (`CORE/processor/comm.php:2443-2463`). 2 rows live (85028, 97000), each right after `infoaction` `Шаман uses Кровать` and an `infosave`. | [V] eventlog; [R] code |
| F3 | The wake event is `goodmorning`. It enters the main pipeline as an "RPG comment" (`CORE/prompts/prompts.php:126-130`) and the core drops it without a log row unless `RPG_COMMENTS` contains `sleep` (`CORE/main.php:1989-1992`). Live `RPG_COMMENTS` = `levelup, bleedout, combat_end`: every wake is dropped today. One logged row (85032, from 2026-10-04 when `sleep` was on) sits exactly 10 000 000 gamets after its `goodnight`. | [V] `core_profiles` id 1, eventlog; [R] code |
| F4 | Plugin `preprocessing.php` hooks run for **every** request type, before the core handles or drops it. So a plugin sees `goodnight`, `goodmorning`, `waitstart`, `waitstop`, `init`, `infosave`. | [R] `CORE/main.php:201` |
| F5 | Neither `goodnight` nor `goodmorning` appears in the Papyrus sources: the DLL sends them. When exactly, and whether `goodmorning` follows every sleep, is unknown. | [V] grep over `PSC` |
| F6 | Waiting: `waitstart` / `waitstop`; the core writes `info_timeforward` with the hours (`comm.php:2465-2483`). 106 rows live: 81 of 1 h, 2 of 24 h, one of 240 h. 28 jumps above 0.2 day sit in the log (waits, sleeps, jail, travel). | [V] |
| F7 | One game day is about 2.6 real hours of play: 1 060 gamets per real second. `ext/tes_crime/lib.php:324` assumes 72 minutes. | [V] rows 120501-121150 |
| F8 | The player's hold is in the text of `request`, `location` and `infoloc` requests (`Hold: Вайтран`). 80 of 86 `location` rows are Вайтран, 3 Хаафингар. | [V] |

### Rollback the platform already does

| # | Finding | Evidence |
|---|---|---|
| F9 | On `init` the core deletes rows with `gamets >= T` from `eventlog`, `speech`, `rumors` (line 146), `memory`, `bgl_history` and others, and empties `responselog` (142). It skips all of it at gamets `10000000` (110) or when `pgr_skip_rollback` is set (129). | [R] `comm.php:107-166` |
| F10 | `rumors`, `eventlog`, `core_npc_master`, `responselog`, `skyrim_quest_action_outbox` are captured per playthrough. `tes_*` tables are not. | [R] `CORE/lib/playthrough_policy.php:6-10` |
| F11 | The active character is in `chim_meta.settings`, key `PLAYTHROUGH_SESSION`: `{"character_id":"3a6f…","profile_id":6,"load_id":2,…}`. | [V] |
| F12 | Native relationships (`extended_data.relationships`) roll back with the save for every NPC, locked ones too. | [R] `13-16-npc-state.md` F4 |
| F13 | What our code does in the game through the console lives in the save file and comes back with it. Item values set by `tesprice` are re-sent by the price tick every 10 minutes; whether a save keeps them is [N]. | [R] `ext/tes_world/services.php:126-166` |

### Channels to the player

| # | Finding | Evidence |
|---|---|---|
| F14 | Rumours: table `rumors(id, gamets, ts, hold, content, type, rumor_length_days)`. An NPC prompt gets the rumours of the player's hold that satisfy `gamets + days x 10^7 > now`, at most 3, unsorted. | [R] `CORE/main.php:2483-2515`, `CORE/lib/lazy_xml.php:68-108` |
| F15 | Our rumour writer `tesGodGuardAddRumor` always uses the **player's** hold and type `TES news` (`ext/tes_god_guard/functions.php:418-438`). `tesRumorTrim` keeps the 3 freshest rows of types `TES news` + `Слух издалека` per hold (`ext/tes_world/rumors.php:100-110`); `tesRumorSpreadTick` carries `TES news` rows to neighbour holds after 30 and 60 real minutes (113-162). Live: 1 rumour, type `Local news`; zero `TES news` rows (the game has not run since the type changed). | [R], [V] |
| F16 | A fact private to one NPC, with native rollback and no standing prompt line: an `eventlog` row of type `infoaction` whose `data` starts with `<memory>` is rendered as speaker `memory`; an NPC sees a row when `people` equals its name or contains `\|name\|`. The window is the last 14 rows (`CONTEXT_HISTORY`). Native Background Life writes its private rows the same way. | [R] `CORE/lib/data_functions.php:2619-2670`, `:2818`; `CORE/service/processors/backgroundlife/cmd/background_action_handler.php:182-196`; [V] `core_profiles`. [N] whether the model picks such a row up in play |
| F17 | Off-screen NPC commands exist in the client: `rolecommand\|BackgroundCmd@0x<ref8>@TravelTo/<location formid, decimal>`, `…@ReturnHome/`, `…@StayAtPlace/<loc>/<intent>`, `…@SendNote/<title>` (the vanilla courier, after `rolecommand\|generateLetter@<title>` and `createLetter()`). | [R] `PSC/AIAgentAIMind.psc:3967-4047`; `background_action_handler.php:175, 433, 512`; `CORE/lib/rolemaster_helpers.php:1218`. Never used by our plugins |
| F18 | Native Background Life is a model call per enabled NPC per 24 game hours (connector 11). One NPC has it on (Назим); `bgl_history` holds 4 rows. | [R] `…/backgroundlife/cmd/main_lw.php`; [V] `general_settings`, `bgl_history` |
| F19 | Screen notice: `rolecommand\|DebugNotification@<text>`, cut at 200 characters (`ext/tes_crime/lib.php:95-102`). 21 rows live. | [V] `responselog` |
| F20 | Speech without a model: `returnLines([$text])` with `HERIKA_NAME` set, then a `ScriptQueue` row (`CORE/lib/core/book_read.class.php:997-1040`); request type `just_say` does the same (`comm.php:1225`). Never called from a plugin hook. | [R] |
| F21 | The Narrator speaks on `rolecommand\|Instruction@The Narrator@<text>@0` (`ext/tes_agent/lib.php:107-115`); the game answers with an `instruction` request (91 rows live). For `instruction` the core turns functions on and adds "Choose the coherent ACTION named in this instruction…" (`main.php:1102-1106`, `2273-2276`). `suggestion` turns functions off (1108-1111). Plugin hooks `prerequest.php` (1128) and `context_pre.php` (2613) run after those lines. | [R], [V] |
| F22 | A Narrator request costs $0.00289 on average: 2 102 logged requests, 9 014 prompt and 153 completion tokens. A 341-token request costs $0.0001. | [V] `audit_request` |
| F23 | Letters never worked: `tes_world_letters` does not exist. Also absent: `tes_place_memory`, `tes_prices`, `tes_companion`, `tes_sanguine_bets`. Designed and not built: `tes_petitions`, `tes_dm_campaign`, `tes_dm_rules`, `tes_state_meta`; directories `ext/tes_state` and `tools/tests` do not exist. | [V] `to_regclass`, `ls` |
| F24 | Prices are global (an item's value at every trader), need bridge 16, and end by real minutes (`services.php:81-88`). The game last ran bridge 15. The index knows base values (`Хлеб` 00065C97 = 4, `Железный слиток` 0005ACE4 = 10). | [R], [V] `tes_watch.bridge_ver`, `tes_game_index` |

### What the world state gives the rules

| # | Finding | Evidence |
|---|---|---|
| F25 | 242 known NPCs. 9 children (race `Ребенок`); the index flags 53 base NPCs as `child`; the two agree for every NPC that resolves. Class `Child` misses one of the 9 (Ларс Сын Битвы is `Citizen`). 31 are dead. 59 have generated refs (`FF…`) that the index does not know. | [V] |
| F26 | C locale: `race ILIKE '%реб%'` matches 0 of 9, `race LIKE '%Реб%'` 9, `lower(race) = 'ребенок'` 0. Child checks work only in PHP (`tesWorldIsChild`, `tesChildSafeIsChildRef`) or by exact match. | [V] |
| F27 | An NPC has no hold column. The index gives it: actor -> base NPC -> `fac` contains the hold's crime faction. Known, adult, alive, unique, not a guard: Вайтран 47, Хаафингар 25, every other hold 0 or 1. 11 of Whiterun's 51 unique adults are `essential`. | [V] |
| F28 | 124 adults have `goals` (median 333 characters, model-written, English and Russian mixed). A 10-class stem classifier places 119 of them; `duty` matches 91, `status` 63, `family` 48, `wealth` 44, `craft` 29, `knowledge` 17, `love` 9, `grudge` 8, `faith` 8, `leave` 7. | [V] script `/tmp/lw_goals.php` over a SELECT |
| F29 | Static `relationships` is JSON keyed by English names (103 NPCs: 33 adults with an enemy or rival, 36 with family, 24 romantic). Native relationships are keyed by Russian display names and hold NPC-to-NPC entries (Изольда -> Кадорд те Глутон, aff -1). | [V] |
| F30 | Treasury 9 658, last ledger row 2026-10-06. `tes_posts` is empty. Court: 3 rows, one open (Ярл Балгруф Старший since 2026-10-06). Jail: 45 rows, 1 `jailed`; columns `jailed_gamets`, `release_gamets`. `tesCrimeJail` uses the player's current hold and walks the arrest when a guard is near (`ext/tes_crime/lib.php:288-330`). | [V], [R] |
| F31 | Unfinished orders: `tes_agent_tasks` has 55 `failed`, 3 `gave_up`, 1 `waiting`; the newest failure is from 2026-10-04. Plot candidates (`realm.php:467-484`, anger >= 6): none today. | [V] |
| F32 | Ticks ride on the game's requests (about every 5 s): `ext/tes_world/preprocessing.php:15-32`. Plugin directories load alphabetically (`CORE/lib/data_functions.php:8016-8034`): `tes_living` runs before `tes_state` and `tes_world`. `tesBridgeVersion()` can block 4.8 s (`watch.php:195-217`). | [R] |

Consequences. (a) F1 + F4 give the day tick and the sleep trigger with no client change. (b) F9 + F14 + F16 give
two outcome channels that roll back by themselves: a rumour and an NPC-private memory row. (c) F27 limits the
living world to Whiterun and Haafingar until the player meets people elsewhere. (d) F23 + F24 mean letters and
prices have never run in the game; no event may depend on them alone.

---

## 2. Feature A: four approaches

### A1. Rule catalogue over a facts snapshot (recommended)

On a day boundary the server loads a read-only snapshot of one hold (people, goals as drive classes, ties,
treasury, court, jail), runs a fixed catalogue of event rules as pure functions, lets a seeded pick choose at most
three, applies each as a list of typed effects through functions the plugins already have, and journals it with
its inverse.

- Cost: one new plugin of about 1 100 lines, 4 tables. 0 model calls. 0 standing prompt lines.
- Cheapest failure: the facts are stale. `is_dead` is only as fresh as the last time CHIM saw the NPC, so a rumour
  can name somebody who died out of sight. Second: 20 templates start to repeat after about ten game days.
- Best when: the budget is zero, undo and determinism are required, and the state tables are rich enough. All three hold.

### A2. Per-NPC simulation (needs, wealth, mood, a daily step for everyone)

Every NPC gets persistent state; a daily step moves all of them; visible events fall out of threshold crossings.

- Cost: about 2 500 lines, state for 240 NPCs in `plugin_extended_data`, balance tuning. Rollback of that state
  needs `13-16-npc-state.md` Part 1, which is not built.
- Cheapest failure: nothing visible for days, then a runaway (everyone broke, everyone jailed). "A few events per
  day" cannot be promised, and undoing one event means recomputing the chain.
- Best when: a campaign runs for 100+ game days and `tes_state` is live. The whole log covers 30 game days.

### A3. A model writes a deck of events, the tick plays it

One call per session turns a state digest into 20 candidate events as JSON; rules validate them; the tick draws.

- Cost: $0.001-0.003 per deck, plus the whole rule layer of A1 as the validator.
- Cheapest failure: the model names a tie or a place that does not exist; the validator rejects most of the deck
  and the tick has nothing to play. The content is not reproducible from a seed.
- Best when: variety of wording matters more than cost. It can be added later over A1 as a text-only layer.
  The brief forbids the call.

### A4. Turn on native Background Life for the townsfolk

CHIM's own system (F18): each enabled NPC decides by a model call what to do, travels, sends letters.

- Cost: 47 NPCs x $0.0017-0.0029 per game day = $0.08-0.14 per 2.6 hours of play in Whiterun alone. No code.
- Cheapest failure: the key runs dry in a day. Outcomes are neither bounded nor journaled by us; the child rule
  and the scene rule are not ours to enforce there.
- Best when: money is no object and control is not needed.

### Recommendation: A1

Against the criteria: (1) A1 and A2 can enforce the child and scene rules in code; A3 only after validation, A4 not
at all. (2) A1 and A2 make no call; A3 and A4 do. (3) Only A1 is bounded per day, journals each event with its
inverse and is a pure function of seed and facts. (4) A1 produces a visible outcome on day one; A2 does not.
(5) A1 reuses the most: the core's own rumour and event-log rollback, native relationships, `tesCrimeJail`,
`tesTreasuryAdd`, `tesPriceSet`, the rumour spread, and A4's client commands (F17) without A4's model.
(6) A1's planner is a pure function over a JSON fixture.

---

## 3. Feature A: design

New plugin `ext/tes_living/`, new files only. It reads `ext/tes_world/lib.php` and `ext/tes_crime/lib.php` with
`require_once` behind `is_readable`, as `tes_world` does with `tes_god_guard`.

### 3.1 Words

- **Tick**: one simulated game day of one hold. Identified by `(pt, hold, day)`.
- **pt**: the active character, `character_id` from F11; `''` when unknown.
- **g_tick**: the gamets of the request that ran the tick; it is the journal row's game time. Every row an op
  writes anywhere carries the gamets of the request that applied that op, never an earlier one. Story time
  ("yesterday morning") is only wording.
- **Event**: one journal row with typed effects. **Hard** event: it moves a person or money in the game
  (`jail`, `away`, `treasury` of 200 or more, `price`).
- **Thread**: an open matter several events share (a debt, an unsolved theft). Threads feed the dreams.

Why `g_tick` and not story time: a tick at day 31, 00:00 that stamped an arrest "day 30, 09:00" would survive a
load of a save from day 30, 12:00, while in that save the man is free. With real request time the rule is
exact: a save older than an op prunes it, a save newer than an op contains its game effect.

### 3.2 Effect operations

Every event is a list of these. No other code path writes for feature A.

| Op | Applied through | Comes back with an old save by | Manual undo |
|---|---|---|---|
| `rumor` | `INSERT` into `rumors`: `hold` = the event's hold, `type` = `TES news`, `gamets` = the applying request's gamets, `rumor_length_days` = 5 | the core (F9) | delete our row by id, after copying it to `tes_backup_rumors` as `tesRumorTrim` does |
| `recall` | `INSERT` into `eventlog`: `type` = `infoaction`, `data` = `<memory>` + English line (max 220 chars), `people` = the NPC's name, `gamets` = the applying request's gamets, `ts` = `DataLastKnownTS()`, `localts` = `time()`, `sess` = `pending` | the core (F9) | delete by rowid |
| `remember` | `tesMemoryAdd($id, $ru)` when `13-16` Part 5 exists; until then `tesGodGuardRemember($id, $ru)` after `tesWorldNeedGuard()` (the `[Помнит]` block that `info.php` sells from) | `tes_state` fix-up, else our compensation (the exact line is removed) | remove the line by exact text |
| `bond` | `tesBondApply($id, $delta, $noteEn)` when `13-16` Part 4 exists; skipped otherwise. NPC towards the player only | native timeline (F12) | apply `-delta` |
| `treasury` | `tesTreasuryAdd($delta, $why)` | `tes_state` snapshot, else our compensation (3.6) | `tesTreasuryAdd(-$delta, 'откат: ' . $why)` |
| `price` | `tesPriceSet($formid, $name, $gold, $why, 0)`; only when `tes_watch.bridge_ver >= 16` (read the row, never call `tesBridgeVersion()`, F32) | our compensation | delete the row, queue `tesprice <dec> <base value from the index>` |
| `jail` | `tesCrimeJail($npc, $ref, $reason, $days, '')` | the save (game), our compensation (row) | `tesCrimeRelease($row, false)` |
| `away` | `responselog`: `rolecommand\|BackgroundCmd@0x<ref8>@TravelTo/<location formid, decimal>` | the save | `…@ReturnHome/` |
| `return` | `rolecommand\|BackgroundCmd@0x<ref8>@ReturnHome/` | the save | none |
| `letter` | `tesWorldLetter($from, $title, $body, $minutes, $items)`; when it returns 0 or throws: `notify` with `Письмо от {from}: {title}` | our compensation (unsent row) | delete the unsent row |
| `notify` | `tesWatchNotify($ru)` (max 190 chars) | nothing to undo | none |
| `thread` | own table (3.4) | own prune | previous values from the `undo` list |
| `plot` | `tes_dm_campaign` through the op `plot_open` / `plot_close` when `god-narrator.md` 7.3 is built; skipped otherwise | that table's own `gamets` | `plot_close` |

When an op runs: `rumor, recall, remember, bond, treasury, price, away, return, thread, plot` at once,
wherever the player is. `jail`, `letter`, `notify` carry `"when": "present"` and wait until the player is in the
event's hold (`tesCrimeJail` works on the player's current hold, F30; a notice about a far hold means nothing).

Not used in v1: place memory (`tesWorldRememberPlace` returns no id to undo by), fear and anger
(`tes_loyalty` is being retired by `13-16`), anything that starts combat, any `teslove`.

Rumour slot rule: CHIM shows 3 rumours per hold and `tesRumorTrim` keeps our 3 freshest (F14, F15). The living
world may hold **one** of them. Before a `rumor` op it deletes its own previous rumour of that hold if the row
still exists. The ruler's own deeds keep two slots.

### 3.3 Who can take part

`tesLivingPeople($hold)` returns the people of one hold, sorted by name in byte order:

```sql
SELECT m.id, m.npc_name, upper(m.refid) AS ref, m.gender, m.race, m.lock_profile,
       coalesce(m.goals, '') AS goals, coalesce(m.occupation, '') AS occupation,
       coalesce(m.relationships, '') AS rel_static, m.extended_data->'relationships' AS rel_native,
       m.extended_data->'class'->>'name' AS class, m.metadata->'activity_status'->>'is_dead' AS dead,
       i.extra->>'child' AS idx_child, i.extra->>'unique' AS idx_unique, i.extra->>'essential' AS idx_essential
FROM public.core_npc_master m
JOIN public.tes_game_index a ON a.kind = 'actor' AND upper(a.formid) = upper(m.refid)
JOIN public.tes_game_index i ON i.kind = 'npc'   AND upper(i.formid) = upper(a.extra->>'base')
WHERE i.extra->'fac' ? :crime_faction
ORDER BY m.npc_name COLLATE "C";
```

Hold table (formids [V] in the index; names as `rumors.hold` and `tesRumorNeighbours()` spell them):

| Hold | Crime faction | Capital location (for `away`) |
|---|---|---|
| Вайтран | 000267EA | WhiterunLocation 00018A56 |
| Хаафингар | 00029DB0 | SolitudeLocation 00018A5A |
| Истмарк | 000267E3 | WindhelmLocation 00018A57 |
| Рифт | 0002816B | RiftenLocation 00018A58 |
| Предел | 0002816C | MarkarthLocation 00018A59 |
| Фолкрит | 00028170 | FalkreathLocation 00018A49 |
| Хьялмарк | 0002816D | MorthalLocation 00018A53 |
| Белый Берег | 0002816E | DawnstarLocation 00018A50 |
| Винтерхолд | 0002816F | WinterholdLocation 00018A51 |

PHP then sets, per person:

- `adult` = race is not empty, does not match `/реб[её]нок|child/iu`, `idx_child` is not `true`, class is not
  `Child`. Unknown race = not adult. **Only `adult === true` people enter the list at all.**
- `alive` = `dead` is not `true`. `guard` = name contains `Стражник` or class starts with `Guard`.
- `eligible` = adult, alive, not guard, `idx_unique` = `true`.
- `movable` = eligible, `idx_essential` is not `true`, no row in `tes_posts`, not jailed (`tesCrimeIsJailed`),
  not away (open `away` thread), not the defendant of an open `tes_court` row, not a companion
  (`tesCompanionList()`), not in an `infonpc` / `infonpc_close` row of the last 120 s. Hard events need `movable`.
- `drive` = the first class in this order that matches the goals text: `leave, grudge, love, faith, knowledge,
  craft, wealth, family, status, duty` (rarest first, F28); `''` when none.
- `nature` = `tesCompanionNature($name)` (`decent | cruel | plain`).
- `trade` = class in `Food Vendor, Pawnbroker, Blacksmith, Apothecary, Tailor`.
- `aff` = native `rel_native.Player.aff`, 0 when absent.
- `kin`, `foes`, `sweet` = names from the static JSON (English keys mapped through the index `editor_id`, as
  `06-07-stories.md` F21 does) with type `familial` and aff >= 40; type `enemy` / `rival` or aff <= -30; type
  `romantic` / `friendly` and aff >= 20. Names that are not in the people list are dropped, so a child never
  appears in any of the three.

A hold takes part only with 6 or more eligible people (today: Вайтран 47, Хаафингар 25).

Child rule, enforced four times: (1) children never enter `people`; (2) the planner refuses any role whose
person has `adult !== true`; (3) `tesLivingApply` refuses an event when any name in it, or any name found in its
rendered texts, belongs to the child list (9 known children plus the names of the 53 `child` base NPCs of the
index) and stores it as `refused`; (4) console sequences go through `tesWorldQueue`, which runs
`tesChildSafeCommands`. Scene rule: the catalogue has no op that starts a scene and no template with such words;
the test in Part 3 greps for them.

### 3.4 Data model

```sql
CREATE TABLE IF NOT EXISTS public.tes_living_ticks (
  pt text NOT NULL, hold text NOT NULL, day int NOT NULL,
  g_tick bigint NOT NULL,
  kind text NOT NULL DEFAULT 'day',          -- baseline | day | entry | remote
  events int NOT NULL DEFAULT 0, facts_hash text NOT NULL DEFAULT '',
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (pt, hold, day));              -- the primary key is the lock: one request wins

CREATE TABLE IF NOT EXISTS public.tes_living_journal (
  id bigserial PRIMARY KEY,
  pt text NOT NULL, hold text NOT NULL, day int NOT NULL,
  gamets bigint NOT NULL,                    -- g_tick, the rollback key
  type text NOT NULL,                        -- catalogue id
  actor text NOT NULL DEFAULT '', actor_ref text NOT NULL DEFAULT '',
  target text NOT NULL DEFAULT '', target_ref text NOT NULL DEFAULT '',
  data jsonb NOT NULL DEFAULT '{}'::jsonb,   -- amounts, days, truth, roll
  text_ru text NOT NULL DEFAULT '',          -- what the player may read or hear
  line_en text NOT NULL DEFAULT '',          -- the memory line for the model
  plan jsonb NOT NULL DEFAULT '[]'::jsonb,   -- effects to apply
  done jsonb NOT NULL DEFAULT '[]'::jsonb,   -- effects applied: the ids they created and the gamets of each
  undo jsonb NOT NULL DEFAULT '[]'::jsonb,   -- inverse ops, filled as effects are applied
  thread_id bigint,
  state text NOT NULL DEFAULT 'planned',     -- planned | applied | partial | refused | undone | rolled_back
  seen_g bigint,                             -- when the player was told (digest, dream)
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (pt, hold, day, type, actor, target));

CREATE TABLE IF NOT EXISTS public.tes_living_threads (
  id bigserial PRIMARY KEY,
  pt text NOT NULL, hold text NOT NULL,
  kind text NOT NULL,                        -- debt | theft | slander | bid | plot | away | grudge | courtship | price
  a text NOT NULL, b text NOT NULL DEFAULT '',
  amount int NOT NULL DEFAULT 0, data jsonb NOT NULL DEFAULT '{}'::jsonb,
  step int NOT NULL DEFAULT 0, due_day int NOT NULL,
  state text NOT NULL DEFAULT 'open',        -- open | closed | expired
  result text NOT NULL DEFAULT '',
  opened_g bigint NOT NULL, closed_g bigint,
  dm_plot_id bigint,
  created_at timestamptz NOT NULL DEFAULT now());

CREATE TABLE IF NOT EXISTS public.tes_dreams (  -- feature B, section 4
  id bigserial PRIMARY KEY,
  pt text NOT NULL, night int NOT NULL,      -- game day of the goodnight request
  g_sleep bigint NOT NULL, g_wake bigint,
  kind text NOT NULL DEFAULT '',             -- hint | warning | false_lead | none
  thread_key text NOT NULL DEFAULT '', truth boolean NOT NULL DEFAULT true,
  brief_en text NOT NULL DEFAULT '', text_ru text NOT NULL DEFAULT '',
  mode text NOT NULL DEFAULT '',             -- model | template | none
  state text NOT NULL DEFAULT 'pending',     -- pending | delivered | skipped
  calls int NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (pt, night));
```

`tes_watch` keys (all global, none snapshotted): `living_off` (`1` = everything stops), `living_off_<type>`
(`1` = that event type is never planned), `dream_mode` (`model | template | off`, default `model`).
File `ext/tes_living/DISABLED` stops everything, as in `13-16`.

There is no clock table. "Last ticked day" is `max(day)` over `tes_living_ticks` for `(pt, hold)`, so pruning the
tick rows rolls the clock back by itself.

### 3.5 Event catalogue

Common to every row: all people `eligible`; hard events need `movable`; an actor takes part in at most one event
per 3 days; a type fires at most once per hold per day; `ruled` = the raw `player_title` row of
`tes_world_titles` names this hold by the prefix rule of `ext/tes_world/lib.php:47-50` (`tesWorldFacts()` answers
only for the hold the player stands in). Weight is the base number the seeded pick divides by. Texts are Russian for the player; `recall` lines are
English. `{H}` = hold, `{n}` = an amount picked by the seed.

| # | Type | Trigger (all must hold) | Effects | The player sees | Undo |
|---|---|---|---|---|---|
| 1 | `tax_grumble` | ruled; `tax_rate` >= 150; two traders A, B. w 8 | rumor `Торговцы {A} и {B} ропщут: налог в {rate}% душит торг.`; recall A; bond A -3 `the ruler's tax chokes his trade` | the rumour from anyone in the hold; A complains | native |
| 2 | `trader_leaves` | ruled; `tesEconomyTraderLoss()` >= 1 or `tax_rate` >= 200; trader A movable; no open `away`. Hard. w 4 | away A to the capital of the first neighbour hold (`tesRumorNeighbours`), 2-3 days; rumor `{A} закрыл лавку и уехал в {H2}: говорит, в {H} стало опасно торговать.`; thread `away` | the stall is empty; the rumour | `ReturnHome`; thread closed |
| 3 | `trader_returns` | thread `away` due | return A; rumor `{A} вернулся в {H} и снова открыл лавку.`; recall A; thread closed | A is back and says where he was | none needed |
| 4 | `price_shift` | `bridge_ver` >= 16; cause from facts: an open `away` of a trader (+30 %), `tax_rate` >= 150 (+25 %), `tax_rate` <= 50 (-20 %); item = seeded pick of `Хлеб 00065C97`, `Железный слиток 0005ACE4`, `Стальной слиток 0005ACE5`, `Кожа 000DB5D2`. Hard. w 5 | price = base value x factor, at least +-1; rumor `В {H} {подорожал\|подешевел} {item}: {cause}.`; thread `price` due next day | the price in the trade window; the rumour | base value back; row deleted |
| 5 | `price_back` | thread `price` due | price back to the base value; thread closed | the old price | none needed |
| 6 | `treasury_gift` | ruled; A with `aff` >= 50 and drive in `status, faith, duty`; at most once in 5 days. w 3 | treasury +n (50-300) `дар: {A}`; rumor `{A} внёс в казну {n} септимов на нужды {H}.`; recall A; notify | the balance; `Куда ушли деньги?` lists the gift | treasury -n |
| 7 | `treasury_theft` | ruled; balance >= 500; culprit A: `aff` <= -40, or drive `wealth` and nature `cruel`; witness W, not A, not kin of A; at most once in 7 days. Hard. w 2 | treasury -n (`min(300, balance / 10)`) `кража`; rumor `Из казны {H} пропало {n} септимов. Стража ищет вора.` (no name); recall A `you took {n} septims from the jarl's treasury at night; you tell nobody`; remember W `Видел своими глазами: {A} ночью выносил из казны тяжёлый мешок.`; thread `theft` (a = A, b = W, due +7) | the balance; the rumour; W sells the secret (`info.php` reads lines with `Видел`); `Суд над {A}` closes the thread | treasury +n; lines removed; thread deleted |
| 8 | `theft_cold` | thread `theft` past due, no closed `tes_court` row for A after `opened_g` | rumor `Вора, обокравшего казну {H}, так и не нашли.`; thread expired | the rumour | thread reopened |
| 9 | `brawl` | A and B, each in the other's `foes`, neither jailed nor away. w 6 | rumor `{A} и {B} сцепились у таверны: {cause}.` (cause = seeded pick of 6); recall both; thread `grudge` step +1 | the rumour; both remember it | native; step back |
| 10 | `arrest` | thread `grudge` at step >= 2; A = its `a`, movable; the hold has a jail (`tesCrimeJails()`); `living_off_arrest` is not `1`. Hard. w 10 | jail A for 1-2 days, reason `драка`; rumor `Стража {H} посадила {A} в темницу на {d} сут. за драку.`; recall A; thread closed | A in the cell in prison clothes; the rumour; in the ruled hold a mercy petition can follow (`06-07` A) | `tesCrimeRelease` |
| 11 | `release_note` | a `tes_crime_jail` row of this hold with `release_gamets` inside the simulated day (the ruler's own sentences too); A adult. w 7 | rumor `{A} вышел из темницы {H}.`; recall A `you served your term in the jail and are free again` | A is free and speaks of it | native |
| 12 | `verdict_echo` | ruled; a `tes_court` row closed with a verdict in the last 2 days, not echoed yet; K = kin of the defendant. w 9 | recall K `the jarl judged your {relation} {X}: {verdict}`; bond K -8 (execution, jail) or +5 (acquitted). No rumour: `court.php` wrote one | K speaks of the sentence; the attitude moves | native |
| 13 | `venture` | A with drive `wealth`, not away; seeded 55 % success. w 5 | success: recall A; every third time rumor `{A} удачно сторговался и ходит довольный.` Failure: thread `debt` (a = A, b = a trader B, amount 60-240, due +2); recall both; rumor `{A} задолжал {B} {n} септимов.` | the rumour; both speak of the debt | native; thread deleted |
| 14 | `debt_call` | thread `debt` due, still open | ruled: letter from B, title `Прошу рассудить`, body `Ярл! {A} должен мне {n} септимов и не платит. Рассуди нас.`; else rumor `{B} при всех требовал с {A} долг.` Thread step +1, due +3. Closes `paid` when `tes_treasury_log.why` = `выдано: {B}` sums to n after `opened_g`, or `judged` on a closed `tes_court` row for A; expires at step 3 with bond B -3 | a letter or notice; `Выдай {B} {n} из казны` or `Суд над {A}` ends it | unsent letter deleted; step back |
| 15 | `office_bid` | ruled; A with drive `status`, `aff` >= 20; a role of `казначей, советник, шут, виночерпий` has no row in `tes_posts`; no open `bid`. w 3 | letter from A, title `Прошение о службе`, body `Ярл! Прошу места {role} при твоём дворе.`; recall A; thread `bid` due +5. Closes when `tes_posts.npc` = A (bond +10); expires with recall `the jarl never answered your plea for a post` and bond -3 | a letter; `Назначаю {A} {role}` ends it | unsent letter deleted; thread deleted |
| 16 | `courtship` | A with drive `love`; B in A's `sweet`; neither has a `spouse` row in `tes_world_facts` nor a static `husband` / `wife` relation; B not kin. w 3 | rumor `{A} зачастил к {B}: носит то цветы, то мёд.`; recall both; thread `courtship` step +1. At step 3: rumor `Поговаривают, {A} и {B} скоро сыграют свадьбу.` and the thread closes. Text only | the rumours; both speak of it | native; step back |
| 17 | `slander` | A with drive `grudge` or B in A's `foes`; confidant C in A's `kin` or `sweet`. w 3 | rumor `Болтают, будто {B} {smear}.` (smear = seeded pick of `обвешивает покупателей, разбавляет эль, не платит долгов, спит до полудня`); `data.truth = false`; recall A; remember C `Слышал своими ушами: {A} сам пустил слух про {B}.`; thread `slander` due +5 | a false rumour; C sells the truth | native; line removed |
| 18 | `craft_gift` | A with class `Blacksmith`, `Apothecary` or `Tailor`, `aff` >= 40; at most once in 7 days. w 2 | letter from A in 5 minutes, title `Подарок мастера`, with one item (`Стальной кинжал 00013986` for a smith; the builder resolves the other two in the index); recall A | the courier brings a gift | unsent letter deleted |
| 19 | `pilgrimage` | A with drive `faith`, movable, no trade; no open `away`. Hard. w 2 | away A to the capital of the first neighbour hold, 2 days; rumor `{A} ушёл на богомолье в {H2}.`; thread `away`; the same `trader_returns` path brings A back with rumor `{A} вернулся с богомолья.` | A is gone, then back | `ReturnHome` |
| 20 | `plot_step` | ruled; `tes_watch.plot_names` set (`realm.php:479`); A, B = its first two names; witness W. w 6 | remember W `Видел своими глазами: {A} и {B} шептались ночью за закрытой дверью.`; thread `plot` step +1. At step 3, when the player talked to neither (no `inputtext` row with `(Talking to A)` or `(Talking to B)` after `opened_g`): away both, 3 days, rumor `{A} и {B} бежали из {H}.` No violence | W sells the secret; the two flee | lines removed; `ReturnHome` |
| 21 | `gratitude` | ruled; a `tes_treasury_log` row `выдано: {X}` in the last 2 days; K = kin of X. w 6 | rumor `{X} всем рассказывает о щедрости ярла.`; recall K; bond K +3 `the jarl was generous to his kin` | the rumour; the kin are warmer | native |
| 22 | `steward_digest` | ruled; the player was absent from the hold for 2 days or more; 2 or more events with `seen_g` empty; S = Провентус Авениччи when eligible, else the `советник` of `tes_posts`. Always fires | letter from S, title `Что было без тебя`, body = up to 5 `text_ru`; sets `seen_g` | one letter or notice that lists what happened | unsent letter deleted |

Rules of taste: no event kills, starts a fight in the game, undresses anybody, or names a crime that carries
death. No template contains ` убил `, `казнил` or `в темницу` except row 10, whose `в темницу` is deliberate: the
petition classifier of `06-07-stories.md` A.4 reads it as `mercy`.

### 3.6 The tick

Entry: `ext/tes_living/preprocessing.php` calls `tesLivingOnRequest($GLOBALS['gameRequest'])` once per request,
inside `try/catch`. Constants: `PER_DAY = 3`, `HARD_PER_DAY = 1`, `CATCHUP_MAX = 3`, `QUIET_PERCENT = 25`,
`REMOTE_PER_DAY = 1`, `DRAIN_GAP_S = 20`.

1. Off switch: `DISABLED` file or `tes_watch.living_off = '1'`: return.
2. `g` = `gameRequest[2]`. Not numeric, `<= 0` or `10000000`: return (F9).
3. `type = init`: unless `pgr_skip_rollback` is set, write `sys_get_temp_dir()/tes_living.pending` =
   `{"T": g, "w": time()}`; return. The work happens on the next request (step 4), after the core and `tes_state`
   have finished, so the plugin load order does not matter (F32).
4. Pending file present: run `tesLivingRollback($pt, $T, $worldRestored)` once, delete the file.
   `$worldRestored` = `tes_state_meta.horizon` exists with the same `T` and `world = 'restored'`.
   Rollback works on ops, not on rows (each `done` entry carries the gamets at which it was applied):
   - `tes_living_ticks` with `g_tick >= T` and `tes_dreams` with `g_sleep >= T` are deleted.
   - Journal rows with `gamets >= T`: every done op is taken back; the row becomes `rolled_back`.
   - Journal rows with `gamets < T`: done ops applied at or after `T` are taken back and return to `plan`; the
     row becomes `planned` again and the drain applies them in the new timeline.
   - Taking an op back on a load: `thread` values are restored from `undo`. Only when `$worldRestored` is false,
     the server-side inverses run for `treasury`, `price` (plus one `tesprice` to the base value), `letter`
     (unsent rows), `remember` (the exact line), and `jail` as `UPDATE tes_crime_jail SET status = 'released'`
     for the row the op created. No game command is sent for `jail`, `away`, `return`: the save restored the
     game. `rumor`, `recall`, `bond` need nothing (F9, F12).
5. Self-heal: `g` is more than 1 000 000 below `max(g_tick)` of this `pt` and no pending file existed: a load
   without `init`. Run step 4 with `T = g` and `error_log('[tes_living] time went back without init')`.
6. Fast path: a mark file holds `pt|hold|day` of the last finished check. Equal: go to step 11.
7. `day = intdiv(g, 10000000)`, `hold` = `Hold:` from this request's text, else `tesWorldCurrentHold()`.
   Unknown hold or a hold with fewer than 6 eligible people: write the mark, go to step 11.
8. For the current hold: `last = max(day)` in `tes_living_ticks`. No row at all: insert `(pt, hold, day - 1,
   g, 'baseline')` and stop (no burst of old news on first sight). Otherwise simulate each day `d` from
   `max(last + 1, day - CATCHUP_MAX)` to `day - 1`. Kind `entry` when the mark file named another hold, else `day`.
9. One simulated day `d`:
   1. `INSERT INTO tes_living_ticks … ON CONFLICT DO NOTHING RETURNING day`. No row back: another request has it; skip.
   2. `facts = tesLivingFacts($pt, $hold, $d, $g)`; store `sha1` of its canonical JSON as `facts_hash`.
   3. `events = tesLivingPlan($facts, tesLivingSeed($pt, $hold, $d))` (3.7).
   4. Each event becomes a journal row in state `planned` with its `plan`. Nothing is applied yet.
10. Far holds: on a day tick, `REMOTE_PER_DAY` other holds with 6 or more eligible people (round-robin by `d`)
    go through steps 8 and 9 with kind `remote`. Their events are planned in full. Ops that need the player
    there wait (step 11); the rest apply, so the rumour reaches the player's hold through the existing spread
    (F15) and the treasury moves while the ruler is away. When the player enters that hold, days already
    ticked are skipped, missing days are caught up, and the waiting ops run.
11. Drain: at most one journal row per request and per `DRAIN_GAP_S`, oldest first, none while
    `tesWorldQueueBusy()`. `tesLivingApply($row, $g)` runs the child gate, then applies the row's pending ops in
    order. Ops with `"when": "present"` run only while the player is in the row's hold; a waiting `jail` is
    dropped when its term has passed (`day > row.day + days`), a waiting `letter` or `notify` after 5 days.
    After each op `done` and `undo` grow by one entry stamped with `g`. State: `applied` when `plan` is empty,
    `partial` while ops wait or after an error (`data.error`). The journal row exists before anything is sent.
12. Feature B hooks (section 4), then write the mark file.

Cost per request outside a tick: three `is_file` and one string compare; one `SELECT` every 20 s while ops wait.

### 3.7 The planner (pure)

`tesLivingPlan(array $facts, string $seed): array`

1. Follow-ups first: every open thread of this hold with `due_day <= d` yields its follow-up event (rows 3, 5,
   8, 14, expiry of 15, step 3 of 16 and 20). They are never dropped: beyond `PER_DAY` they wait a day.
2. Quiet day: `tesLivingRoll($seed . '|quiet', 100) < QUIET_PERCENT`: return only the follow-ups.
3. Candidates: each catalogue rule returns zero or more `{type, actor, target, w, hard, data}` from the facts.
   Rules read nothing but `$facts`.
4. Drop candidates whose type is switched off, whose actor or target had an event in the last 3 days
   (`facts.recent`), or whose people fail `adult === true`.
5. Order by `score = tesLivingRoll($seed . '|' . type . '|' . actor . '|' . target, 1000000) / w`, ascending;
   ties by `type, actor, target` in byte order.
6. Take from the top while: total with follow-ups `<= PER_DAY`, hard `<= HARD_PER_DAY`, each type once, each
   person once, at most one event carrying a `rumor` op (later ones lose that op and keep the rest).
7. Render texts (`tesLivingRender`), build `plan`.

`tesLivingRoll($key, $n)` = `hexdec(substr(hash('sha256', $key), 0, 8)) % $n`. No `random_int`, no
`ORDER BY random()`, no wall clock inside the planner. Same seed and same `facts_hash` give the same events, so a
reload cannot reroll fate.

### 3.8 Undo by hand

`tools/living_admin.php`: `--status` (ticks, journal counts by state, open threads), `--dry-tick <hold> [day]`
(prints the planned events from live facts, writes nothing), `--dump-facts <hold>` (JSON fixture for tests),
`--undo <journal id>` and `--undo-day <hold> <day>` (run the `undo` lists newest first, game commands included,
state `undone`). The two `--undo` forms change data and the game: the owner's yes.

### 3.9 What builds on other designs, and what it does not duplicate

- `god-narrator.md` 7.3: the Narrator's memory of plots stays there. The living world mirrors at most one thread
  per day of kinds `theft`, `plot`, `away` as a `plot` row through `plot_open` (title Russian, 60 chars; `data` =
  `{"source":"tes_living","thread_id":…}`) and closes it with the thread. While 7.3 is not built this is a no-op.
- `god-narrator.md` 6.9: the living world creates no standing rules. Its closing conditions are server-table
  checks inside its own tick, as `06-07` petitions do.
- `13-16-npc-state.md`: reuses `tesBondApply`, `tesMemoryAdd`, the stamped-by-game-time rule, the pending file,
  the `DISABLED` switch and the admin-tool shape. The four tables above are **not** added to
  `ext/tes_state/world_tables.php`: they prune themselves by `gamets`. The one coupling is `$worldRestored`.
- `06-07-stories.md`: a living-world `arrest` or `debt_call` can raise a petition there; nothing is called.
  Part 3's tests publish the rumour templates so `petition_rules` can add them as fixtures.

---

## 4. Feature B: dreams

### 4.1 Three ways to speak a dream

| | How | Cost per night | Cheapest failure | Best when |
|---|---|---|---|---|
| B1 (recommended) | at wake, one `rolecommand\|Instruction@The Narrator@[DREAM] …@0`; our hooks switch actions off for that request | $0.0029 (F22) | the Narrator answers with a wish-agent action instead of speech; guarded twice (4.5) | the Narrator's voice and his memory of what he said matter, and no new infrastructure is wanted |
| B2 | our own 350-token call from a detached worker, then speech through `returnLines` (F20) | $0.0001 | `returnLines` outside the main pipeline does not produce audio; the only LLM client we have lives in a CLI worker (`ext/tes_agent/worker.php:113`) | dreams become frequent enough for $0.003 to matter |
| B3 | turn the core's `sleep` comment on and inject the brief into that request | $0.0029 x the core's 20 % dice | the core chooses who speaks (a follower, not the Narrator) and how often; it changes a live setting | never: we lose control of the speaker |

B1: one call, bounded by a unique row, speech by the voice the player knows as the god, zero new moving parts.
B2 stays possible behind the same `tesDreamDeliver` contract.

### 4.2 Trigger

- `goodnight` (F2): `INSERT INTO tes_dreams (pt, night, g_sleep) … ON CONFLICT DO NOTHING`. Nothing else.
- Wake = the first of: a `goodmorning` request (F3, F4), or any request with `g >= g_sleep + 833 334` (2 game
  hours). Shorter sleeps give no dream: the row becomes `skipped`.
- Order inside one request: load handling, day tick, drain, then the dream. A sleep usually crosses midnight, so
  the night's events are in the journal before the dream is built.
- Claim: `UPDATE tes_dreams SET state = 'delivered', g_wake = :g … WHERE id = :id AND state = 'pending'
  RETURNING id`. Only the request that gets the row back delivers. This is the "one call per night" guarantee.

### 4.3 Open threads (sources)

`tesDreamSources($pt, $hold, $g)` runs read-only queries, each behind `to_regclass`, and returns items
`{key, kind, urgency 1-5, subject, object, amount, place, age_days, mystery bool}`.

| Source | Query | kind | urgency |
|---|---|---|---|
| living-world threads | `tes_living_threads` open, this `pt` | `theft, debt, plot, slander, away, bid` | 5 for `theft` and `plot`, 3 others; +1 when `due_day - day <= 1` |
| last night's events | `tes_living_journal` applied, `seen_g` empty, `day >= night - 1` | `news` | 2 |
| pending trials | `tes_court WHERE NOT closed` | `trial` | 4 |
| prisoners | `tes_crime_jail WHERE status = 'jailed'` and `release_gamets - g < 10000000` | `release` | 3 |
| unfinished orders | `tes_agent_tasks WHERE status IN ('failed','gave_up','waiting')`, the 5 newest of the last 3 real days; `tes_order_checks WHERE stage <> 'done'` | `order` | 3 |
| ignored rumours | active `rumors` of this hold older than one game day whose first known name has no `inputtext` row `(Talking to name)` and no `tes_court` row after the rumour | `rumor` | 2 |
| petitions | `tes_petitions` in `offered` / `accepted` (when `06-07` is built) | `petition` | 3; 4 when due within a day |
| campaign plots | `tes_dm_campaign WHERE kind = 'plot' AND state = 'open'` (when 7.3 is built), not mirrored from us | `plot` | 4 |
| a god's debt | `tes_watch` keys `clavicus_%` with a value | `debt_god` | 5 |

`mystery` is true for `theft`, `plot`, `slander`. Items whose subject or object is in the child list lose that name.

### 4.4 The builder (pure)

`tesDreamPick(array $items, array $recentKeys, string $seed): ?array` and
`tesDreamCompose(array $item, string $kind, string $seed): array`.

1. No items: no dream, no call. Silence is a valid night.
2. Drop items whose `key` was dreamt in the last 3 nights, unless nothing else is left.
3. Score `urgency x 10 - age_days + tesLivingRoll($seed . '|' . key, 10)`; the highest wins.
4. Kind: `false_lead` when the item is a mystery and `tesLivingRoll($seed . '|false', 5) === 0`;
   `warning` when a deadline is within one day or the kind is `release`, `debt_god`, `trial`; else `hint`.
5. Images: each kind has 4 Russian images and their English twins; the seed picks two.
   A true dream uses the images of the item's own kind and one detail from the item (the trade of the person,
   the place, the size of the sum: `малая`, `немалая`, `большая`). A person appears as a trade or an epithet
   (`кузнец`, `торговка`, `человек в тюремной рубахе`); a name is used only for `trial` and `release`, where the
   player already knows it.
6. A false lead takes its detail from a **different kind's** image pool and names nobody. It never points at a
   real person. `truth = false` is stored.

Example pools (the builder writes all of them into `dream_rules.php`):

| Kind | Images (ru / en) |
|---|---|
| `theft` | `открытый сундук, в замке торчит чужой ключ` / an open chest with a stranger's key in the lock · `мешок, из которого сыплются монеты на ночную мостовую` / a sack spilling coins on a night street · `пустая полка в казне` / an empty shelf in the treasury · `следы, ведущие от твоей двери` / footprints leading away from your door |
| `trial` | `пустое кресло напротив трона` / an empty chair facing the throne · `весы, что качаются без груза` / scales swinging with nothing on them · `человек, который ждёт и не смеет сесть` / a man who waits and dares not sit · `незапертая дверь темницы` / an unlocked cell door |
| `debt` | `два человека тянут один кошель` / two men pulling one purse · `зарубки на дверном косяке` / notches on a door frame · … |
| `plot` | `две тени под одной свечой` / two shadows under one candle · `нож, завёрнутый в хлеб` / a knife wrapped in bread · … |

Template, zero calls (one per dream kind; `{i1}`, `{i2}` are images, `{d}` the detail):

- `hint`: `Тебе снилось: {i1}. Потом {i2}. Проснувшись, ты помнишь одно: {d}.`
- `warning`: `Сон был тревожным: {i1}, а за ним {i2}. Голос сказал: «{d} — не медли».`
- `false_lead`: `Сон путался: {i1}, и вдруг {i2}. Утром остаётся лишь {d}.`

`text_ru` is at most 380 characters and is cut into two notices of at most 190 (F19).

The brief for the model (English, a one-off request text, not a standing line):

```
[DREAM] The player has just woken. Tell him the dream he had this night, as the voice that sent it. Two short
sentences in Russian, second person («Тебе снилось…»), images only, no explanation. The dream shows: {i1_en};
{i2_en}. What it means, which you do NOT say outright: {meaning_en}. Do not say how you know it, give no orders,
start no action.
```

`{meaning_en}` for a true dream is the fact (`somebody close to the court took 300 septims from the treasury and
one person saw it`); for a false lead it is the false detail, marked `(this is a false trail; keep it vague)`.
At most 600 characters after filling (`tesAgentNarratorSay` cuts at 900).

### 4.5 Delivery

`tesDreamDeliver(array $dream): string` returns the mode it used.

1. `dream_mode = off`: `none`.
2. Model mode when `dream_mode = model`, the budget row allows it (`tes_watch.budget_state.left >= 0.30`, the
   rule of `06-07` C.4) and `tesAgentNarratorSay` exists: one `Instruction` row, `calls = 1`, mode `model`.
3. Otherwise template mode: `tesWatchNotify` for each chunk of `text_ru`, `calls = 0`, mode `template`.
4. Either way: journal rows named by the dream get `seen_g`; when `god-narrator.md` 7.3 is built, one `deed` row
   `sent a dream ({kind}) about {thread_key}` so the Narrator knows what he sent.

The dream request must only speak (F21). Two guards in our own hook files, no core edit:

- `ext/tes_living/prerequest.php`: request type `instruction` and text starts with `[DREAM]`:
  `$GLOBALS['FUNCTIONS_ARE_ENABLED'] = false; $GLOBALS['TES_DREAM_NOW'] = true;`
- `ext/tes_living/context_pre.php`: when `TES_DREAM_NOW`: `$GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = false;
  $GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS'] = '';`

Speech for the template mode (F20) is left out of v1: it has never run from a hook. It can replace step 3 later
without touching the builder.

Cost: at most $0.0029 per game night in model mode, $0 in template mode. A game night comes about once per 2.6
hours of play unless the player sleeps more often.

---

## 5. Contracts

### 5.1 Facts (the planner's only input)

```json
{ "v": 1, "pt": "3a6f…", "hold": "Вайтран", "day": 30, "g": 310000512,
  "ruled": true, "player": "Шаман",
  "holdstate": { "tax_rate": 100, "treasury": 9658, "trader_loss": 0, "bridge": 15, "has_jail": true,
                 "neighbours": ["Фолкрит", "Хьялмарк"], "vacant_roles": ["казначей", "советник"],
                 "plot_names": [], "absent_days": 0, "off": ["arrest"] },
  "people": { "Назим": { "ref": "0001A6A4", "id": 2757, "adult": true, "eligible": true, "movable": true,
                          "essential": false, "trade": false, "class": "Citizen", "drive": "status",
                          "nature": "plain", "aff": -10, "kin": ["Алам"], "foes": [], "sweet": [],
                          "spouse": true, "jailed": false, "away": false } },
  "court":   [ { "defendant": "…", "closed": true, "verdict": "…", "age_days": 1, "echoed": false } ],
  "jail":    [ { "npc": "…", "release_day": 30 } ],
  "payouts": [ { "who": "…", "sum": 300, "age_days": 1 } ],
  "threads": [ { "id": 7, "kind": "debt", "a": "…", "b": "…", "amount": 120, "step": 0, "due_day": 30 } ],
  "recent":  [ { "type": "brawl", "actor": "…", "target": "…", "day": 28 } ],
  "children": ["Брейт", "Дагни", "…"] }
```

Maps are sorted by key in byte order; lists by their first field. The loader never writes.

### 5.2 Event (planner output, journal `plan`)

```json
{ "type": "treasury_theft", "actor": "…", "actor_ref": "0001A6…", "target": "…", "target_ref": "…",
  "hard": true, "data": { "amount": 300, "truth": true, "roll": 412233 },
  "text_ru": "Из казны Вайтран пропало 300 септимов. Стража ищет вора.",
  "line_en": "you took 300 septims from the jarl's treasury at night; you tell nobody",
  "plan": [ { "op": "treasury", "delta": -300, "why": "кража" },
            { "op": "rumor", "text": "…" },
            { "op": "recall", "npc": "…", "line": "…" },
            { "op": "remember", "npc": "…", "text": "Видел своими глазами: …" },
            { "op": "thread", "do": "open", "kind": "theft", "a": "…", "b": "…", "amount": 300, "due": 37 } ] }
```

Ops `jail`, `letter`, `notify` carry `"when": "present"` (3.2).

### 5.3 Functions

| File | Function | Contract |
|---|---|---|
| `core.php` | `tesLivingValidG($g): bool` | numeric, > 0, not `10000000` |
| | `tesLivingDay(int $g): int`, `tesLivingHour(int $g): float` | F1 |
| | `tesLivingRoll(string $key, int $n): int` | 3.7 |
| | `tesLivingSeed(string $pt, string $hold, int $day): string` | `'tl1\|' . $pt . '\|' . $hold . '\|' . $day` |
| | `tesLivingHolds(): array` | the table of 3.3: hold => `['faction', 'capital', 'capital_name']` |
| | `tesLivingDrive(string $goals): string` | 3.3 |
| | `tesLivingIsAdultRow(array $row): bool` | 3.3, pure |
| | `tesLivingEnabled(): bool`, `tesLivingOff(string $type): bool` | switches |
| `facts.php` | `tesLivingPt(): string` | F11, `''` when the schema or key is missing |
| | `tesLivingPeople(string $hold): array` | 3.3 |
| | `tesLivingChildren(): array` | names: 9 known + index `child` base NPCs |
| | `tesLivingFacts(string $pt, string $hold, int $day, int $g): array` | 5.1 |
| `rules.php` | `tesLivingCatalog(): array` | id => `['w', 'hard', 'ruled', 'rule' => callable($facts): array]` |
| | `tesLivingPlan(array $facts, string $seed): array` | 3.7, list of 5.2 |
| | `tesLivingRender(array $event, array $facts): array` | fills `text_ru`, `line_en`, `plan` |
| | `tesLivingTemplates(): array` | every Russian template, for other designs' fixtures |
| `io.php` | `tesLivingIo(string $op, array $args): array` | the only writer; with `$GLOBALS['TES_LIVING_DRY']` it appends `[$op, $args]` to `$GLOBALS['TES_LIVING_LOG']` and writes nothing. Returns `['ok' => bool, 'id' => mixed, 'undo' => ?array]` |
| `effects.php` | `tesLivingEnsure(): void` | the four `CREATE TABLE` |
| | `tesLivingChildGate(array $event, array $children): bool` | false when a child's name is in any field or text |
| | `tesLivingApply(array $journalRow, int $g): string` | new state |
| | `tesLivingUndo(int $journalId, bool $withGame): array` | ops undone |
| | `tesLivingRollback(string $pt, int $T, bool $worldRestored): array` | counts per op |
| `tick.php` | `tesLivingSteps(array $st, string $type, int $g, string $hold): array` | pure; returns steps from `['pending', 'rollback', 'baseline', 'tick:<d>', 'remote:<hold>:<d>', 'drain', 'sleep', 'wake']` |
| | `tesLivingOnRequest(array $gameRequest): void` | 3.6 |
| `dream_rules.php` | `tesDreamPick`, `tesDreamCompose` | 4.4 |
| | `tesDreamImages(): array` | kind => list of `[ru, en]` |
| `dream.php` | `tesDreamSources`, `tesDreamOnSleep(int $g)`, `tesDreamOnWake(int $g)`, `tesDreamDeliver` | 4.2-4.5 |

If `06-07-stories.md` Part P0 (`story_io.php`) is built first, `io.php` shrinks to delegations to `tesStory*`;
its contract does not change.

---

## 6. Parts for builders

Build order: 1, then 2, 3, 6 in parallel, then 4, then 5, then 7, then 8. Parts 1 to 7 create new files only.
No part calls a model. No test sends anything to the game.

Every test: `runuser -u www-data -- php REPO/tools/<test>.php` inside the distro (from Windows prefix
`MSYS_NO_PATHCONV=1 wsl -d DwemerAI4Skyrim3 -u root --`). Pass = last line `ALL OK`, exit code 0. Tests boot as
`tools/test_court.php:9-18` does. Tests that write use `pt = 'ZZZ_test_<pid>'` and hold `ZZZ_TestHold_<pid>` and
delete their rows at the end.

### Part 1. Core helpers

- Files: `ext/tes_living/manifest.json`, `ext/tes_living/core.php`, `tools/test_living_core.php`.
- Contract: 5.3 `core.php`.
- Acceptance: day of 219800512 is 21 and its hour is between 23.5 and 23.6; `10000000`, `0`, `'abc'` are invalid;
  the same key gives the same roll 1 000 times; 10 000 different keys fall into 10 buckets with 8-12 % each;
  `tesLivingDrive` gives `wealth` for `* Eventually save enough money to purchase his own small farm`, `love` for
  `Жениться на Камилле`, `''` for `Engage in combat`; on the live table at least 110 of the adults with goals
  get a drive (119 today); `tesLivingIsAdultRow` is false for race `Ребенок`, for race `''`, for `idx_child =
  'true'`, for class `Child`, and true for `Норд` with all flags clear.
- Depends on: nothing.

### Part 2. Facts loader

- Files: `ext/tes_living/facts.php`, `tools/test_living_facts.php`.
- Contract: 5.3 `facts.php`, shape 5.1.
- Acceptance, read-only on the live database: Whiterun has 40 to 60 people (47 today); none of Брейт, Дагни,
  Дорти, Ларс Сын Битвы, Люсия, Мила Валентия, Нелкир, Сварри, Фротар is a key of `people` or a member of any
  `kin`, `foes`, `sweet`; nobody dead, nobody with `Стражник` in the name; every `ref` is 8 hex digits;
  `tesLivingChildren()` has at least 9 names; two calls return byte-identical JSON; the call takes under 500 ms;
  the row counts of `rumors`, `eventlog`, `responselog`, `skyrim_quest_action_outbox` are unchanged.
- Depends on: Part 1.

### Part 3. Catalogue and planner

- Files: `ext/tes_living/rules.php`, `tools/fixtures/living_facts_whiterun.json`,
  `tools/fixtures/living_facts_haafingar.json`, `tools/test_living_rules.php`.
- Contract: 5.3 `rules.php`, 3.5, 3.7. The fixtures come from `living_admin.php --dump-facts`; until Part 5
  exists the builder dumps them with a 5-line script over Part 2.
- Acceptance: two runs on the same fixture and seed give byte-identical JSON; every result has at most 3 events,
  at most 1 hard, at most 1 with a `rumor` op; no person in any role has `adult !== true`; a fixture where A's
  only foe is `Мила Валентия` yields no `brawl`; with `tax_rate` 200 the result of at least one of 20 seeds holds
  `tax_grumble` or `trader_leaves`; the Haafingar fixture (`ruled` false) never yields types 1, 2, 6, 7, 12, 15,
  20, 21, 22; 30 consecutive days with `recent` fed back never use one person twice within 3 days; about 25 % of
  400 seeds are quiet (20-30 %); a due `debt` thread always yields `debt_call`; no `text_ru` contains `{`; no
  `line_en` contains a Cyrillic word that is not a name from the fixture; no text contains a name from
  `children`; no template matches `/ убил |казнил/u`; none matches
  `/секс|трах|разде|голы|обнаж|постел|sex|naked|undress/iu`; `в темницу` occurs only in type `arrest`.
- Depends on: Part 1.

### Part 4. Effects, journal, undo

- Files: `ext/tes_living/io.php`, `ext/tes_living/effects.php`, `tools/test_living_effects.php`.
- Contract: 5.3 `io.php`, `effects.php`; 3.2; 3.6 step 4.
- Acceptance. Dry mode: each of the 13 ops logs exactly one entry of its own name and the row counts of
  `rumors`, `eventlog`, `responselog`, `skyrim_quest_action_outbox`, `tes_treasury_log` are unchanged; an event
  naming `Мила Валентия` in `target` or inside `text_ru` ends `refused` with an empty log; `bond` logs `skipped` when
  `tesBondApply` is absent; `remember` logs the fallback writer; `price` logs `skipped` at `bridge` 15; `away` builds
  `rolecommand|BackgroundCmd@0x0001a6a4@TravelTo/100954` for ref `0001A6A4` and capital `00018A5A`
  (`hexdec('00018A5A')` = 100954). Live mode with the `ZZZ` keys: `rumor` inserts one row with that hold, type
  `TES news`, `gamets` = the given g, and `tesLivingUndo` removes it; `recall` inserts one `eventlog` row with
  `people` = `ZZZ_Person` and undo removes it; a second living rumour in the same hold leaves one row. Rollback:
  journal rows at g 100, 200, 300 and ticks at the same g: `tesLivingRollback($pt, 200, false)` leaves the row at
  100 `applied`, marks the other two `rolled_back`, deletes two tick rows, logs one compensating `treasury` for
  the row that had one; with `$worldRestored = true` it logs no `treasury`; a second call changes nothing; a row at g 100 whose
  one op was applied at g 250 goes back to `planned` with that op in `plan` again.
- Depends on: Parts 1, 3.

### Part 5. Tick, hook, admin tool, deploy

- Files: `ext/tes_living/tick.php`, `ext/tes_living/preprocessing.php`, `tools/living_admin.php`,
  `tools/deploy_tes_living.sh` (same shape as `tools/deploy_tes_world.sh`), `tools/test_living_tick.php`.
- Contract: 3.6, 3.8, 5.3 `tick.php`.
- Acceptance. `tesLivingSteps` pure cases: gamets `10000000` gives `[]`; `init` gives `['pending']`; a request
  with a pending file gives `rollback` first; the same `pt|hold|day` as the mark gives only `drain`, `wake` when
  due; first sight gives `['baseline']`; day 31 after a tick of day 29 gives `['tick:30']`; day 40 after day 29
  gives ticks 37, 38, 39; a hold with 3 people gives no tick; `goodnight` gives `sleep`. End to end in dry mode
  with the `ZZZ` keys and the Whiterun fixture: request at day 30 writes one baseline row and no journal row;
  request at day 31 writes journal rows for day 30; the same request again writes nothing; `init` at a gamets
  inside day 30 followed by a request removes the day-30 tick, and the next day-31 request plans the same events
  (same `type, actor, target` list); with the `DISABLED` file nothing is written; one drain call applies at
  most one row; a `jail` op planned for `ZZZ_TestHold` stays in `plan` while the request names another hold and
  is applied on the first request that names it. `php tools/living_admin.php --dry-tick Вайтран` prints events and changes no row count.
- Depends on: Parts 2, 3, 4.

### Part 6. Dream builder

- Files: `ext/tes_living/dream_rules.php`, `tools/test_living_dream_rules.php`.
- Contract: 4.4, 5.3.
- Acceptance: one fixture item per kind of 4.3 gives a dream; no items gives `null`; `text_ru` is at most 380
  characters and splits into at most 2 chunks of at most 190; `brief_en` is at most 600 characters, starts with
  `[DREAM]` and has no Cyrillic outside `«…»` and names; an item whose subject is `Люсия` gives texts without
  that name; over 500 seeds a mystery item is a `false_lead` in 15-25 % and a `trial` item never; a false lead
  contains no name from the item; the same seed gives the same dream; with keys `a` and `b` available and `a` in
  `recentKeys`, `b` is picked; every kind has at least 4 image pairs.
- Depends on: Part 1.

### Part 7. Dream sources, sleep hooks, delivery

- Files: `ext/tes_living/dream.php`, `ext/tes_living/prerequest.php`, `ext/tes_living/context_pre.php`,
  `tools/test_living_dream.php`. One edit in Part 5's `tick.php`: the `sleep` and `wake` steps call
  `tesDreamOnSleep` / `tesDreamOnWake` when they exist.
- Contract: 4.2, 4.3, 4.5.
- Acceptance. Dry mode, `ZZZ` keys: `goodnight` at g then a request at g + 400 000 delivers nothing; a request
  at g + 900 000 delivers once; the next request delivers nothing; a `goodmorning` request right after
  `goodnight` + 900 000 delivers once; a sleep of 300 000 ends `skipped`; with `budget_state.left` 0.1 the log
  has `notify` entries and no `instruct`; with 1.5 it has exactly one `instruct` whose text starts with `[DREAM]`
  and is at most 900 characters; with `dream_mode = off` nothing. `tesDreamSources` runs on the live database
  without error with the three unbuilt tables absent, and lists the open trial when `tes_court` has one (today:
  Ярл Балгруф Старший). Hook flags: with `gameRequest = ['instruction', 0, 1, '[DREAM] x']` the `prerequest.php`
  body leaves `FUNCTIONS_ARE_ENABLED` false and `TES_DREAM_NOW` true; with `'do something'` it changes neither.
- Depends on: Parts 4, 5, 6.

### Part 8. Wiring into existing files (last; needs nobody else editing them)

- Files (existing): `ext/tes_world/panel.php` (a section: ticks, journal with an undo button, threads, dreams),
  `docs/ROADMAP.md`, `docs/in-game-tests.md`, `/tmp/tes_commit.sh` (run the seven tests), `tools/after_update.sh`
  (run `test_living_core.php` as a core-contract check: `rumors` and `eventlog` still have `gamets`,
  `comm.php` still deletes `rumors` by `gamets`).
- Acceptance: `php -l` clean; `tools/test_court.php` ALL OK; `tools/test_ext.php` at 107 passed, 2 old
  failures; the seven living tests ALL OK.
- Depends on: all.

### Launch

1. Deploy with `ext/tes_living/DISABLED` present. 2. `living_admin.php --dry-tick Вайтран` for three days of
facts; the owner reads the output. 3. On his yes remove `DISABLED` with `living_off_arrest`,
`living_off_trader_leaves`, `living_off_pilgrimage`, `living_off_plot_step` set to `1` (text and treasury only).
4. After the game check of section 9, clear those keys one at a time.

---

## 7. Assumptions

| # | Assumption | Status | If false |
|---|---|---|---|
| A1 | `gamets` = days x 10^7 for every request type | [V] on 3 rows, [R] in the core | one constant in `core.php` |
| A2 | Plugin preprocessing sees `goodnight` and `goodmorning` | [R] `main.php:201` | wake falls back to "first request 2 hours later"; sleep falls back to `infoaction` `uses Кровать` / `uses Койка` (4 rows live) |
| A3 | The DLL sends `goodmorning` after every sleep | [N] (1 logged of 2, logging was dice-gated) | the gamets fallback delivers the dream at the next request |
| A4 | A `<memory>` `infoaction` row with `people` = the name reaches that NPC's prompt and the model uses it | [R]; no such row checked live [N] | switch `recall` to `tesMemoryAdd` (needs `13-16` Part 5) or to one conditional prompt line under 60 tokens for the actor of an event of the last 2 days |
| A5 | A rumour with our hold name and type `TES news` reaches NPCs of that hold | [R]; hold names match the live row `Вайтран` [V] | nothing shows; visible in the panel; fix the hold spelling table |
| A6 | `BackgroundCmd TravelTo` and `ReturnHome` work for an actor in an unloaded cell | [R] client source only | types 2, 19, 20 stay switched off; the rest is unaffected |
| A7 | `tesCrimeJail` works on an actor far from the player | [N] (live arrests were near the player) | type 10 stays off |
| A8 | `tesWorldLetter` delivers | [N] (table absent, F23) | every letter op falls back to a notice by design |
| A9 | `character_id` in `PLAYTHROUGH_SESSION` changes when the character changes | [V] the key exists; [N] the switch | `pt` is constant: two characters share one journal; a load by one prunes the other's tail. Add `load_id` checks |
| A10 | Suppressing functions in `prerequest.php` survives until the model call | [R] line order only | the Narrator may call an action on a dream; second guard: `tes_agent/functions.php` refuses GodCommand while `TES_DREAM_NOW` (an edit of an existing file) |
| A11 | Static relationship keys map to Russian names through the index `editor_id` | [V] for 4 by `06-07` F21 | kin, foes, sweet are empty: types 9, 12, 16, 17, 21 never fire; the rest works |
| A12 | `is_dead` in `metadata` is fresh enough | [N] | a rumour about a dead person; add "seen alive within 5 game days" to `eligible` |
| A13 | One living rumour slot of three per hold is the right share | not verified | one constant |
| A14 | `tes_state` (when built) writes `horizon` before the request after `init` | [R] `13-16` 4.3 | double compensation of the treasury on a load; detect by `tes_treasury_log` rows `откат:` and skip |

---

## 8. What can go wrong in production

| Failure | Noticed by | Undone by |
|---|---|---|
| A child is named or touched | Parts 3 and 4 tests fail before deploy; at run time journal rows in state `refused` | not deployable; `living_off = 1` |
| An event about a dead or absent person | the owner hears it; `--status` shows the row | `--undo <id>`; A12's extra filter |
| A quest NPC sits in jail or is away when a vanilla quest needs him | the owner; open `away` / jail rows in `--status` | `--undo <id>` (release, `ReturnHome`); `living_off_arrest`, `living_off_trader_leaves` |
| Memory of the future after a load | journal rows `applied` with `gamets` above the newest `eventlog` gamets (`--status` prints the count) | `living_admin.php --status` then `--undo`; the self-heal of step 5 |
| The treasury is compensated twice | ledger rows `откат:` right after a load that `tes_state` also restored | `tesTreasuryAdd` by hand with the owner's yes; A14 |
| Rumour flood | more than one living rumour per hold in `rumors` | the slot rule; `living_off = 1` |
| Console queue overload (bridge races) | `tesWorldQueueBusy()`; journal rows stay `planned` | the drain waits; nothing is lost |
| The tick slows requests | `error_log('[tes_living] tick ms=…')` above 300 | `DISABLED` |
| The Narrator acts on a dream | a `tes_agent_tasks` row within 30 s of a `tes_dreams` delivery | `dream_mode = template` |
| Dream spend | `sum(calls)` in `tes_dreams`; `tes_watch.budget_state` | `dream_mode = template` or `off` |
| Anything else | | `touch CORE/ext/tes_living/DISABLED`; the tables stay |

---

## 9. What cannot be verified without the game

1. A4: an NPC mentions a `recall` line unprompted or when asked `Что нового?`.
2. A5: a living rumour is heard in the right hold and the neighbour hold gets the distorted copy.
3. A6: an NPC sent away is gone from his usual place and comes back after `ReturnHome`.
4. A7: an arrest planned by the tick ends with the NPC in the cell, and the term ends by itself.
5. A8: a letter arrives (inventory paper today, courier after PLAN P8).
6. `price_shift` on bridge 16: the trade window shows the new value and the old one returns.
7. A3 and the timing: the dream is spoken right after waking, not during the sleep menu.
8. A10: the dream request produces speech only.
9. A load of an older save: the journal shrinks, the same day ticks again with the same events.
10. Taste: whether three events a day feel alive or noisy. This is the owner's call (section 10).

---

## 10. Open questions for the owner

1. May the world jail a named townsman for a day or two on its own (type 10), in every hold or only where you rule?
2. May townsfolk leave town for days (types 2, 19, 20)? A quest can need them while they are away.
3. Three events per hold per day, one of them "hard": the right amount?
4. False dreams: one night in five on mysteries, never naming a person. Keep, change the rate, or drop?
5. Dreams by the Narrator's voice for $0.003 a night, or silent text notices for free?
6. Should the treasury theft be solvable only through a paid witness and a trial, or should the steward also
   name a suspect after some days?

---

## 11. Found on the way (not part of this design)

- `ext/tes_crime/lib.php:324` converts a game day to 72 real minutes; the measured rate is about 157 (F7). If
  `release_at` is what frees prisoners, they leave at 46 % of the term said. I did not read the release path.
- `ext/tes_world/court.php:120-122` uses `random_int` for the tax; the ledger is not reproducible after a reload.
- `tesGodGuardAddRumor` cannot write a rumour for a hold the player is not in (F15); `06-07-stories.md` F1
  still says type `Local news`, the code says `TES news`.
- `ext/tes_world/info.php:19` prefers memory lines that contain `Видел` and falls back to the newest line; a
  witness line written in English would lose that preference.
- The pure parts (`rules.php`, `dream_rules.php`, `core.php`) have no Skyrim dependency: facts in, events out.
  They carry over to an own game unchanged; only `facts.php` and `io.php` are platform code.
