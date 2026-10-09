# Design 2026-10-09: petitions born from rumours (roadmap 6) and the ruler on trial (roadmap 7)

Status: design only, nothing built. Read against `main` @ 2eb8266 and the live database on 2026-10-09.
Line numbers are from that commit; the English-prompt merge (PLAN P3) will shift them, so every hook also
names the code text to look for.

Tags: **[V]** verified by running a query or reading live data today, **[R]** read in code, not run,
**[N]** not verified (needs the game).

Inside tables `\|` is the table's escape for `|`: copy regexes and wire strings with a plain `|`.

Criteria, in the brief's order: (1) bridge v16 as it is, (2) no core change, (3) no or rare model calls,
(4) no harm to children or vanilla quests, (5) testable without the game.

---

## 0. What the system gives us (evidence)

| # | Fact | Source | Tag |
|---|---|---|---|
| F1 | A rumour is one row in `public.rumors` (`id, gamets, ts, hold, content, type, rumor_length_days`); ours are inserted by `tesGodGuardAddRumor()` with `type = 'Local news'` and the player's current hold | `ext/tes_god_guard/functions.php:418-438`; table columns | V |
| F2 | Our rumour texts come from fixed Russian templates: death `Говорят, {killer} убил {victim} ({weapon}) ({place}); это видели A, B.` (`witness.php:120,130`), sentence `Говорят, на суде {player} {who} {verdict}.` (`court.php:418`), orders `{player} казнил {who}. …` / `бросил {who} в темницу` / `раздели` / `забрал у {who}` / `просит милостыню` (`realm.php:369-375`), plot `Шепчутся, что A и B недовольны ярлом…` (`realm.php:482`), overheard `Подслушали, как …` (`overhear.php:55`), the player's own `Говорят, будто …` (`fame.php:100`) | code; 27 rows in `tes_backup_rumors` | V |
| F3 | Rumours are trimmed once a minute to the 8 newest per hold, duplicates removed (`postrequest.php:75-89`); roadmap 14 will cut this to 3. A rumour row can vanish: copy its text, never keep only the id | code | R |
| F4 | A paper letter with a delay exists: `tesWorldLetter($sender, $title, $body, $minutes, $items)` + `tesWorldLetterTick()` (`world.php:30-64`). Table `tes_world_letters` does not exist yet: the code has never run in the game | `to_regclass` is NULL | V |
| F5 | The treasury pays a named person on `Выдай X 300 из казны` and logs `why` = `выдано: X` in `tes_treasury_log` (`court.php:163-181`) | code | R |
| F6 | A trial of an NPC leaves `tes_court(defendant, closed, verdict, opened_at)`; jail leaves `tes_crime_jail(npc, status, release_at)` (`court.php:27-32,413`; `tes_crime/lib.php:171-189,320`) | `tes_court` has 3 rows | V |
| F7 | The server sees who the player talks to: `eventlog.type = 'inputtext'`, data ends with `(Talking to <name>)` | 566 rows | V |
| F8 | The server sees a native arrest end to end. Rows 72037-72078 (2026-10-03 23:01:40-23:01:56): `itemfound` `Шаман submitted to arrest and was sent to jail.` -> `location` `(Context new location: Драконий Предел - Подземелье ,Hold: Вайтран, …` -> `infoaction` `Шаман uses Койка` -> `info_timeforward` `24.0000096 hours have passed. …` -> `itemfound` `Шаман found 1501600 Септим in a Сундук с имуществом пленных` -> `location` `(Context new location: Вайтран ,Hold: Вайтран, …` | eventlog | V |
| F9 | Other native crime rows: `itemfound` `Боргни [Стражник Вайтрана] added 40 gold bounty to Шаман for crimes in this hold.` (71233), `itemfound` `Шаман paid 64 gold bounty. Stolen items confiscated.` (95085) | eventlog | V |
| F10 | The client's `ArrestPlayer` shows a submit/resist window; submit calls `crimeFaction.SendPlayerToJail(true, true)`, resist calls `SetPlayerEnemy()` and `StartCombat`; it logs `… is attempting to arrest …`, `… submitted to arrest and was sent to jail.`, `… resisted arrest. Guards are attacking.` | `MO2/mods/AIAgent/Source/Scripts/AIAgentAIMind.psc:3301-3344` | R |
| F11 | A plugin can send a native NPC command: `tesCrimeNpcCommand($npc, 'MoveTo@X')` writes `responselog(actor = npc, action = 'command\|MoveTo@X')`; MoveTo, TravelTo, Follow, Relax work live this way (`funcret` rows `command@MoveTo@…`). `ArrestPlayer` was only ever chosen by a guard's model (`funcret` 72042 `command@ArrestPlayer@@Player arrested and sent to jail`) | `tes_crime/lib.php:76-82`; eventlog | V for MoveTo, N for ArrestPlayer |
| F12 | CHIM's ScriptProxy has `Faction->SendPlayerToJail($faction, $removeInventory, $realJail)`, `SetCrimeGold`, `ModCrimeGold`, `PlayerPayCrimeGold`; it is sent with `$builder->send($cmd)` (`rolecommand\|ScriptProxy@{json}`) | `HerikaServer/lib/scriptproxy_papyrus.php`; used by `tes_god_guard/functions.php:2069-2087` | R |
| F13 | The player's bounty can be read today: sequence `prid <guard ref>`, `getcrimegold` answers `Actor Crime Gold is 200.00`; `player.setcrimegold 0 000267EA` answers `Actor Crime Gold for faction Вайтран has been set to 0` | `tes_god_console_log` ids 4385-4411, 12202 | V |
| F14 | Impunity is `tes_watch.impunity` (`'0'` = off, anything else or no row = on). No row exists now: it is on. `tesCourtSpoken()` handles the phrase **before** it checks the title (`court.php:139-147` vs `148`). While on, `watch.php:171-178` re-sends `tesimpunity 1` and `player.addfac 000267EA 0` every 3 minutes; turning it off sends only `tesimpunity 0` | code; `tes_watch` | V |
| F15 | Every order, court, treasury and tax handler is gated by `!empty(tesWorldFacts()['player_title'])`: `preprocessing.php:163`, `court.php:111,148`, `realm.php:568`, `lib.php:812`, `postrequest.php:109`. The fact is one row `tes_world_titles(key = 'player_title')` | code | R |
| F16 | Children: 9 NPCs with `race = 'Ребенок'` (Брейт, Дагни, Дорти, Ларс Сын Битвы, Люсия, Мила Валентия, Нелкир, Сварри, Фротар). The database runs in the C locale: `race ILIKE '%реб%'` matches 0 of them. Child checks work only in PHP (`tesWorldIsChild`, `tesChildSafeIsChildRef`). Every console sequence sent through `tesWorldQueue()` passes `tesChildSafeCommands()` (`lib.php:424-425`) | queries | V |
| F17 | Ticks ride on the game's own requests (about every 5 s): `preprocessing.php:15-32` (types `request, infonpc, infonpc_close, infoloc`) and `postrequest.php:96` | code | R |
| F18 | Plugin hook files are loaded by `requireFilesRecursively()` (alphabetical, recursive) (`HerikaServer/lib/data_functions.php:8016-8034`). `tools/deploy_tes_world.sh` copies `ext/tes_world/*` with plain `cp`: a subdirectory would break the deploy | code | R |
| F19 | Player gold is 999 500 820, level 252; `0000000F` is `Септим`, `0000000A` is `Отмычка` | `skyrim_quest_instances` state JSON, `playerinfo`, `tes_game_index` | V |
| F20 | SNQE has never held a quest (`sneq_quests` = 0, `sneq_quests_saved` = 0). A quest is PHP code in `sneq_quests.code` run by a separate service; `CheckTopicToPlayer` calls a model up to 50 times per topic (`snqe/lib/api.php:897-1010`, `lib/rolemaster_helpers.php:1379`); a save load truncates the table and restores one quest (`snqe.class.php:158-184`). No SNQE or service process was running at check time | queries, `ps` | V |
| F21 | `core_npc_master.relationships` is JSON keyed by **English** names (`"Mila Valentia": {"type": "familial", "relation": "daughter", "aff": 95}`); `tes_game_index` maps `editor_id` (`MilaValentia`) to the Russian name and, through `kind = 'actor'` + `extra.base`, to the refid. 103 of 242 NPCs have such JSON | queries | V for 4 samples |
| F22 | The client has a tracker quest: `rolecommand\|StartQuest@title@text`, `UpdateQuest`, `EndQuest`, `QuestTrackReference@<formid>` (`AIAgentAIMind.psc:2779-2836`; used by `snqe/lib/api.php`) | code | R, N in game |
| F23 | `tes_witness_seen` gets `killer, victim, witnesses` from an `ALTER … ADD COLUMN IF NOT EXISTS` in the first witness tick (`witness.php:58`). The live table has only `key, deed, created_at` and 0 rows | query | V |
| F24 | Of the people a judge could be, Провентус Авениччи, Айрилет and Командир Кай are alive; Вигнар Серая Грива is dead (`metadata.activity_status.is_dead = 'true'`) | query | V |

---

# A. Petitions born from rumours (roadmap 6)

The player is the jarl, so an "errand" is a petition (прошение): somebody touched by what the rumour tells
asks the ruler to act, and the act is one the ruler can already perform by voice.

## A.1 Three approaches

### A-1. Rule-based petitions over our own rumour templates (recommended)
A tick reads fresh `Local news` rumours of the ruled hold, classifies each by keyword and by the known names in
it, picks a living adult petitioner, sends a paper letter, and closes the petition when a server-side fact
appears (a closed trial, a treasury payout, a release, a talk).
- Cost: 3 own files plus the 2 shared parts of section C; 0 background model calls; about 60 prompt tokens only
  for the petitioner and the subject while a petition is open.
- Cheapest failure: a rumour whose wording differs from the templates is not classified, and nothing happens.
  Silent, harmless; seen as "no petitions" on the panel.
- Best when: the rumours that matter are the ones our plugins write (F2), and the budget is near zero. Both hold.

### A-2. SNQE quest from a template (the roadmap's wording: "SNQE + new NPC")
The plugin fills a PHP quest template (`CreateNPC`, `SpawnNPC`, `CreateTopic`, `TellTopicToPlayer`,
`WaitForCoins`, `CompleteQuest`) and inserts it into `sneq_quests`; SNQE spawns a petitioner and drives the talk.
- Cost: no model call to create it, but the engine calls a model per delivered topic (`SkTopicCheck`, up to 50
  attempts) plus one NPC request per `Instruction`: 5 to 50 calls, $0.015 to $0.15 per quest, unbounded by us.
- Cheapest failure: the SNQE service is not running, the row stays `not started` forever (F20). Second cheapest:
  a save load truncates `sneq_quests` and the petition is gone.
- Best when: the key has $10 or more, SNQE is proven in this modlist, and a spawned stranger is wanted more than
  a known townsman. None holds today.

### A-3. One model call per rumour writes the errand
A background call turns the rumour into JSON `{giver, kind, target, text}`; validators check names against
`core_npc_master`.
- Cost: $0.003 per rumour, capped at 3 per hour: $0.009 per hour.
- Cheapest failure: the model names a dead person, a child, or an objective no server fact can confirm; the
  errand can never close. It needs the whole rule layer of A-1 anyway as validator.
- Best when: variety of wording matters more than cost. It can be added later as a text-only layer over A-1
  (rules choose kind and people, the model writes the letter).

Rejected without a full write-up: starting vanilla radiant quests (`BQ01`, `Favor0xx`) through `setstage` or
`StartQuest`. Their aliases are filled by the Story Manager; a forced start breaks them (facts.md section 3,
row 6). It violates criterion 4.

## A.2 Recommendation: A-1

Against the criteria: (1) uses only what works on any bridge: `responselog` notifications, paper letters,
existing console sequences; (2) no core change; (3) 0 background calls; (4) children are never petitioner,
subject or target, and no quest is touched; (5) classifier, drafter and completion check are pure functions fed
with literal strings.
Reuse: the deeds are existing phrases (`Суд над X`, `Приговариваю…`, `Выдай X 500 из казны`, `Верни как было`),
the letter is `tesWorldLetter`, the reward is `tesLoyaltyBump`, fame counts itself from the same tables
(`fame.php:25-33`).

## A.3 Trigger

Every 60 s, only while `tesWorldFacts()['player_title']` is set (this already means "in the ruled hold",
`lib.php:44-58`) and `tes_watch.petitions_off <> '1'`:

```sql
SELECT id, hold, content, ts, gamets
FROM public.rumors
WHERE id > :cursor AND type = 'Local news' AND ts > :now - 21600
ORDER BY id LIMIT 10;
```
`:cursor` is `tes_watch.petition_rumor_id`. First run sets it to `max(id)` and returns (as `witness.php:50-54`).
A rumour is skipped when it starts with `Говорят, ярл ` / `Говорят, ярла ` (the prefixes every rumour written by
A or B uses) or with `Подслушали` / `Говорят, будто`.

## A.4 Kinds

| kind | Rumour says | Petitioner (first that is alive, adult, not jailed) | Asks | Closed `done` when |
|---|---|---|---|---|
| `justice` | ` убил `, first name is the killer, killer is not the player and is alive | adult kin of the victim, else a witness named after `это видели`, else adviser / steward | a trial of the killer | `tes_court`: `defendant = killer AND closed AND verdict <> '' AND opened_at > created_at` |
| `bloodprice` | the player `казнил` / `убил` somebody, or `на суде … приговорён к казни` | adult kin of the dead; none -> no petition | wergild 500 | `tes_treasury_log`: `why` equals `выдано: {giver}`, `sum(-delta) >= amount`, newer than the petition |
| `mercy` | `в темницу` / `к темнице`, the named person has `tes_crime_jail.status = 'jailed'` | adult kin, else adviser / steward | release | the subject's latest jail row is `released` while `release_at > now()` |
| `restitution` | `раздели`, `забрал у`, `просит милостыню`, `оштрафован` | the person himself | 300 from the treasury | a payout to the subject as in `bloodprice` |
| `plot` | `замышляют` with two names | adviser (`tes_posts.role = 'советник'`), else steward, else housecarl | a talk with both | `eventlog` has an `inputtext` row with `(Talking to A)` and one with `(Talking to B)`, `rowid > from_rowid` |

A petition closes `done` even from state `offered`: doing the deed is accepting it.
Kin of X: the people in X's `relationships` JSON and the people whose JSON names X, with `type` in
`familial, romantic, protective, mentor, platonic` and `aff >= 40`, resolved through F21.

## A.5 State

```sql
CREATE TABLE IF NOT EXISTS public.tes_petitions (
  id          serial PRIMARY KEY,
  dedupe      text NOT NULL UNIQUE,            -- kind|subject|floor(ts / 86400)
  rumor_id    int  NOT NULL DEFAULT 0,
  rumor_text  text NOT NULL DEFAULT '',
  hold        text NOT NULL DEFAULT '',
  kind        text NOT NULL,                   -- justice|bloodprice|mercy|restitution|plot
  giver       text NOT NULL,
  giver_ref   text NOT NULL DEFAULT '',
  subject     text NOT NULL DEFAULT '',
  subject_ref text NOT NULL DEFAULT '',
  target      text NOT NULL DEFAULT '',        -- justice: the victim; plot: the second person
  amount      int  NOT NULL DEFAULT 0,
  title       text NOT NULL,                   -- Russian, the letter's title
  body        text NOT NULL,                   -- Russian, the letter's text
  line_en     text NOT NULL DEFAULT '',        -- English fact line for prompts
  state       text NOT NULL DEFAULT 'offered', -- offered|accepted|done|failed|refused|expired
  result      text NOT NULL DEFAULT '',
  letter_id   int  NOT NULL DEFAULT 0,
  from_rowid  bigint NOT NULL DEFAULT 0,       -- max(eventlog.rowid) at creation
  gamets      bigint NOT NULL DEFAULT 0,       -- for roadmap 13 ("clean the future")
  created_at  timestamptz NOT NULL DEFAULT now(),
  accepted_at timestamptz,
  due_at      timestamptz NOT NULL,            -- offered: +40 min; accepted: +144 min (two game days)
  closed_at   timestamptz
);
```
`tes_watch` keys: `petition_rumor_id` (cursor), `petition_at` (rate limit), `petitions_off`.
Limits: 1 `offered` at a time, 2 `accepted`, 10 minutes between offers, rumour age up to 6 h.
The insert is `INSERT … ON CONFLICT (dedupe) DO NOTHING RETURNING id`; the letter and the notification are sent
only when a row comes back, so two requests in the same second cannot double-offer.

Outcomes:
- `done`: `tesLoyaltyBump(giver, -1.0, -3.0)`, rumour `Говорят, ярл внял прошению: {short}.`, a thank-you letter
  in 5 minutes, `tesWorldRememberPlace`. Notification `Прошение исполнено: {title}.`
- `refused`, or `failed` (accepted and past `due_at`): `tesLoyaltyBump(giver, 0.0, 2.0)`, rumour
  `Говорят, ярл остался глух к прошению: {short}.`
- `expired` (offered and past `due_at`): nothing.

## A.6 What the player says

Russian phrases (the regex runs on the lower-cased line with `ё` -> `е`):

| Says | Regex | What happens |
|---|---|---|
| «Какие прошения?» · «Что просит народ?» · «Есть жалобы?» | `(как\p{L}+\|есть\|покажи\|огласи\|зачитай)\s+(?:\p{L}+\s+){0,2}?(прошени\p{L}*\|жалоб\p{L}*\|челобитн\p{L}*)\|что\s+прос\p{L}+\s+(народ\|люди\|подданные)` | the one spoken to gets an English note listing the open petitions and reads them out; one Russian notification per petition |
| «Берусь» · «Принимаю прошение» · «Я займусь этим» · «Беру это дело» | `(?<![\p{L}])(берусь\|возьмусь\|принимаю\s+прошени\p{L}*\|займусь\s+(?:этим\|прошени\p{L}*\|делом)\|беру\s+(?:это\s+)?дело\|рассмотрю\s+прошени\p{L}*)(?![\p{L}])` | the offered petition (the one whose giver or subject is named or spoken to, else the newest) becomes `accepted`; notification `Прошение принято: {title}. Срок — два дня.` |
| «Отклоняю прошение» · «Отказываю» | `(?<![\p{L}])(отклоня\p{L}*\s+прошени\p{L}*\|прошени\p{L}*\s+отклон\p{L}*\|отказываю\|в\s+прошении\s+отказано)(?![\p{L}])` | `refused`; only when a petition is `offered` |
| «Хватит прошений» · «Принимаю прошения» | `хватит\s+прошени\p{L}*\|прошени\p{L}*\s+не\s+принимаю` · `(снова\s+)?принимаю\s+прошения` | `petitions_off` = `1` / `0` |
| then the deed: «Суд над X» … «Приговариваю…» · «Выдай X 500 из казны» · «Верни как было» · a talk with A and B | existing handlers, unchanged | the next tick finds the fact and closes the petition |

New-petition notification: `Прошение от {giver}: {title}. Скажи «Берусь» или «Отклоняю прошение».`

Letters (Russian, verbatim templates; `{…}` filled by the drafter):
- `justice`: title `Прошение о правосудии`, body `Ярл! {killer} убил {victim}{seen}. Прошу суда над убийцей. Скажи «Берусь» — и я буду знать, что ты услышал.`
- `bloodprice`: `Прошение о вире` / `Ярл! По твоему слову не стало {victim}. Кровь не вернуть, но виру платят и ярлы: {amount} септимов из казны для {giver}.`
- `mercy`: `Прошение о милости` / `Ярл! {subject} сидит в твоей темнице. Прошу: отпусти — срок ещё не вышел, а дом без него пуст.`
- `restitution`: `Прошение о возмещении` / `Ярл! По твоему приказу я лишился своего. Прошу {amount} септимов из казны — и обида забудется.`
- `plot`: `Донесение` / `Ярл! {a} и {b} шепчутся против тебя. Поговори с обоими, пока шёпот не стал ножом.`

Model-facing lines are English. `line_en` example: `You sent the Jarl a petition: you ask him to put {killer} on
trial for killing {victim}. If he raises it, repeat the plea in your own words, briefly.` After `done`:
`The Jarl granted your petition ({title}). Thank him once.`

## A.7 Parts

Shared parts P0 and W are specified in section C. Own parts:

### A1 `ext/tes_world/petition_rules.php` (pure, no database)
Produces:
```php
tesPetitionSkip(string $rumor): bool
tesPetitionClassify(string $rumor, string $player, array $names): ?array
  // ['kind', 'actor', 'subject', 'target', 'others' => [...], 'short' => string]
  // $names: full npc_name strings; matched as exact substrings, longest first
tesPetitionDraft(array $class, array $ctx): ?array
  // $ctx = ['player', 'now', 'people' => [name => ['ref','alive','child','jailed','guard']],
  //         'kin' => [name => [names]], 'adviser' => name|'', 'steward' => name|'']
  // -> ['kind','giver','giver_ref','subject','subject_ref','target','amount','title','body','line_en','dedupe']
  //    null when no petitioner is alive, adult and free, or when the subject or target is a child
tesPetitionCheck(array $petition, array $facts): string   // 'done' | 'failed' | 'open'
  // $facts = ['now', 'court' => [[defendant, verdict, closed, ts]], 'payouts' => [[who, sum, ts]],
  //           'jail' => [[npc, status, release_ts]], 'talked' => [name => count]]
tesPetitionPhrase(string $line): string                    // '' | 'list' | 'accept' | 'refuse' | 'off' | 'on'
```
Acceptance: `runuser -u www-data -- php tools/tests/run.php petition_rules` prints `ALL OK` with at least these
cases: the 5 templates of F2 give the 5 kinds; `Хеймскр, жрец Талоса, казнён по приказу ярла Шамана.` never
yields the player as subject; a rumour starting `Говорят, ярл внял` is skipped; a draft whose only kin is
`Мила Валентия` (`child = true`) returns the fallback petitioner or `null`, never the child;
`tesPetitionCheck` returns `done` for a trial closed after creation and `open` for one closed before it; each
phrase of A.6 maps to its intent and `Как дела?` maps to `''`.
Depends on: nothing.

### A2 `ext/tes_world/petition.php` (store and tick)
Produces: `tesPetitionEnsure()`, `tesPetitionTick()`, `tesPetitionOffer(string $rumor, int $rumorId = 0): int`
(classify, draft, insert, letter, notification; returns the id or 0), `tesPetitionContext(array $names): array`
(loads `people`, `kin`, posts), `tesPetitionFacts(array $petition): array`, `tesPetitionOpen(): array` (rows in
state `offered` or `accepted`). All writes go through P0's wrappers.
Acceptance: `php tools/tests/run.php petition` in dry mode:
`tesPetitionOffer('Говорят, Скьор убил Карлотта Валентия; это видели Браксек [Стражник Вайтрана].')` logs exactly
one `letter`, one `notify`, one `sql` effect and no `queue` effect, and the letter's sender is not
`Мила Валентия`; `tesPetitionFacts()` runs against the live database without error (read-only).
Depends on: A1, P0.

### A3 `ext/tes_world/petition_talk.php` (speech and prompt lines)
Produces: `tesPetitionSpoken(string $line, string $to): string` (returns the English note or `''`; sets
`$GLOBALS['TES_COURT_NOW'] = true` when it consumed the line, as `world.php:151` does) and
`tesPetitionLine(string $me): string`.
Acceptance: `php tools/tests/run.php petition_talk` in dry mode with one fixture petition: `Берусь` returns a
note that contains the title and logs one `sql` effect setting `accepted`; `tesPetitionLine` returns `''` for a
bystander and `line_en` for the giver; with no petition every phrase returns `''`; every returned note has no
Cyrillic outside `«…»`.
Depends on: A1, A2, P0.

## A.8 In-game test list (owner)

1. `Посади Назима на 3 дня`. Within 2 minutes: notification `Прошение от …` and a courier letter `Прошение о милости`.
2. Ask anyone `Какие прошения?`: he reads the list.
3. `Берусь`, then `Верни как было`. Within a minute: `Прошение исполнено`; 5 minutes later a thank-you letter.
4. Execute an adult who has family. Letter `Прошение о вире`. `Выдай <имя просителя> 500 из казны` closes it.
5. When a rumour `… убил …` about two townspeople appears: letter `Прошение о правосудии`; `Суд над <убийца>`,
   a sentence, and it closes.
6. `Отклоняю прошение`: the petitioner sounds angrier next time; a rumour `ярл остался глух` appears.
7. `Хватит прошений`: no new ones for the session. `Принимаю прошения`: they return.
8. No letter is ever signed by a child (Брейт, Дагни, Дорти, Ларс, Люсия, Мила, Нелкир, Сварри, Фротар).

---

# B. The ruler on trial (roadmap 7)

## B.1 Three approaches

### B-1. The vanilla jail as the stage, a server-side case file on top (recommended)
The server keeps a case against the ruler from what it already sees (witnessed killings, native bounty rows, a
bounty probe). When a guard is near it makes him arrest the player with CHIM's own `ArrestPlayer`. The jail,
the bunk, the evidence chest and the escape are vanilla; the server adds a judge, witnesses, a rule-made
verdict, bribes, and takes the ruler's power away while he is held.
- Cost: 4 own files plus the 2 shared parts; up to 3 background model calls per case ($0.009).
- Cheapest failure: the plugin-sent `ArrestPlayer` does nothing (F11). Noticed in 20 s (no `is attempting to
  arrest` row), answered by the voice path `Сдаюсь` -> ScriptProxy `SendPlayerToJail` (F12).
- Best when: the native jail round trip works in this modlist. It did on 2026-10-03 (F8).

### B-2. A court without a cell
The same case file, but nobody is moved: the council summons the ruler by letter, the trial is a talk in
Dragonsreach, the sentence is server-side only (a fine into the treasury, the title suspended for N minutes).
- Cost: 3 files, 2 model calls per case.
- Cheapest failure: the player ignores the summons and nothing visible ever happens. "Escape" has no meaning.
- Best when: the native jail proves unusable (inventory lost, scripted scenes broken). It is the fallback: B-1
  with the `jailed` state skipped.

### B-3. A Story Manager quest in the bridge
A quest on the Story Manager nodes reports `OnStoryCrimeGold`, `OnStoryArrest`, `OnStoryJail`,
`OnStoryServedTime`, `OnStoryEscapeJail` to the server (facts.md section 2).
- Cost: an ESP edit in the Creation Kit, a new bridge version, a full restart, a mid-playthrough plugin change.
- Cheapest failure: a mis-wired node sends nothing, silently; found only in the game.
- Best when: exact vanilla events matter (theft, trespass, arrests through the vanilla dialogue). It fails
  criterion 1. If exact counters are wanted later without an ESP, the one new bridge command would be
  **`tesjailstat`**: it logs `tesjailstat@@jailed <Game.QueryStat("Times Jailed")>; escapes <Game.QueryStat("Jail Escapes")>; days <Game.QueryStat("Days Jailed")>; bounty <CrimeFactionWhiterun.GetCrimeGold()>`
  (bridge 17). B-1 does not need it.

Also rejected: running the trial as a Narrator-agent task. 9 of 10 agent patrols failed at the step limit
(`postrequest.php:191-194`) and each run costs 10 or more calls.

## B.2 Recommendation: B-1

(1) Console commands already seen in the log, the native `ArrestPlayer`, ScriptProxy as second channel; no new
bridge command. (2) No core change. (3) At most 3 background calls per case, at most one case per 20 minutes.
(4) Judge and witnesses are never children; every sequence goes through `tesWorldQueue()`; the jail is the
vanilla one; no stage is set. (5) The whole story is a pure reducer replayed on the recorded rows of F8.

## B.3 Trigger

A case exists only while `tes_watch.impunity = '0'` (F14), the title is set, the player is in the ruled hold
and `tes_watch.ruler_off <> '1'`.

Charges (each has a weight on the vanilla scale that CHIM prints at `functions/json_response.php:257`:
assault 40, theft 100, jailbreak 100, murder 1000):

```sql
-- C1 murder in front of people (witness.php fills this table within 60 s of a death)
ALTER TABLE public.tes_witness_seen ADD COLUMN IF NOT EXISTS killer text NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS victim text NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS witnesses text NOT NULL DEFAULT '';
SELECT key, deed, victim, witnesses, created_at
FROM public.tes_witness_seen
WHERE killer = :player AND witnesses <> '' AND created_at > :ruler_wseen
ORDER BY created_at;                                   -- weight 1000 each

-- C2 and the jail lifecycle, one cursor
SELECT rowid, type, data, localts, gamets
FROM public.eventlog
WHERE rowid > :ruler_rowid AND type IN ('itemfound', 'location', 'infoaction', 'info_timeforward')
ORDER BY rowid LIMIT 200;
```
`itemfound` `… added N gold bounty to {player} for crimes in this hold.` is a charge of weight N.

C3, the probe, every 90 s while impunity is off, when `tesCrimeNearestGuard('')` names a guard:
`tesWorldQueue(['prid <guard ref>', 'getcrimegold'])`, then the first `tes_god_console_log` row with
`id > :mark AND lower(command) = 'getcrimegold'`; `/Actor Crime Gold is (\d+)/` (F13). N > 0 is a charge
`bounty` of weight N (it replaces the previous `bounty` charge, never adds to it).

"Caught": the case is `charged`, its weight is 40 or more, a living guard is near (`infonpc_close` newer than
30 s, not `(far away)`), no `engages combat with {player}` row in the last 30 s, and the ruler holds no open
trial of his own (`tes_court`). Then the guard is sent `tesCrimeNpcCommand($guard, 'ArrestPlayer@')`.

A `location` row naming the jail never opens a case and never means arrest by itself: the jarl walks into his
own dungeon (row 120164). It only confirms or ends `jailed`.

## B.4 State

```sql
CREATE TABLE IF NOT EXISTS public.tes_ruler_case (
  id           serial PRIMARY KEY,
  state        text NOT NULL DEFAULT 'charged',  -- charged|confront|jailed|trial|decided|closed
  outcome      text NOT NULL DEFAULT '',         -- served|escaped|acquitted|fined|bribed_guard|bribed_judge|paid|resisted|pardoned|lapsed
  hold         text NOT NULL DEFAULT '',
  charges      jsonb NOT NULL DEFAULT '[]',      -- [{kind, who, weight, witnesses: [..], src, at}]
  weight       int  NOT NULL DEFAULT 0,
  attempts     int  NOT NULL DEFAULT 0,
  channel      text NOT NULL DEFAULT '',         -- '' | popup | voice
  confirmed    boolean NOT NULL DEFAULT false,   -- a jail location row was seen
  guard        text NOT NULL DEFAULT '', guard_ref text NOT NULL DEFAULT '',
  judge        text NOT NULL DEFAULT '', judge_ref text NOT NULL DEFAULT '',
  witnesses    text NOT NULL DEFAULT '',         -- 'A|B'
  silenced     text NOT NULL DEFAULT '',
  held_refs    text NOT NULL DEFAULT '',         -- refs moved into the cell and held: all must be freed
  plea         text NOT NULL DEFAULT '',         -- '' | guilty | innocent
  verdict      text NOT NULL DEFAULT '',         -- '' | acquitted | fine | jail
  fine         int  NOT NULL DEFAULT 0, days int NOT NULL DEFAULT 0,
  bribe_tries  int  NOT NULL DEFAULT 0, bribe_paid bigint NOT NULL DEFAULT 0,
  held_title   text NOT NULL DEFAULT '', held_title_gamets bigint NOT NULL DEFAULT 0,
  bunk_at      timestamptz,
  calls        int  NOT NULL DEFAULT 0,          -- background model calls spent on this case
  gamets       bigint NOT NULL DEFAULT 0,
  created_at   timestamptz NOT NULL DEFAULT now(),
  state_at     timestamptz NOT NULL DEFAULT now(),
  closed_at    timestamptz
);
CREATE UNIQUE INDEX IF NOT EXISTS tes_ruler_case_one_open ON public.tes_ruler_case ((true)) WHERE state <> 'closed';
```
`tes_watch` keys: `ruler_rowid`, `ruler_wseen`, `ruler_probe`, `ruler_probe_id`, `ruler_carry` (JSON charge
carried into the next case after `resisted` or `escaped`), `ruler_cool` (20 minutes after a close), `ruler_off`,
`ruler_channel` (`popup`, `voice` or `dead`, remembered between cases).

Every transition is a compare-and-set: `UPDATE tes_ruler_case SET state = :new, state_at = now(), … WHERE id = :id AND state = :old RETURNING id`.
Effects run only when the row comes back.

### Power is suspended in custody
On entering `jailed`: copy the `player_title` row (`fact`, `gamets`) into `held_title`, `held_title_gamets`
and delete it from `tes_world_titles`. All order, court, treasury, tax and patrol code goes quiet by its own
gate (F15) without an edit. On every close, and at `decided` with a verdict other than `jail`:
`INSERT … ON CONFLICT (key) DO NOTHING` puts it back. `Включи безнаказанность` still works without the title
(F14) and is the player's way to stop the story at any moment.

### Transitions

| From | Event | Guard | To | Effects |
|---|---|---|---|---|
| none | `charge` | impunity off, title set, `ruler_cool` older than 20 min or a carried charge | `charged` | notify R1; add `ruler_carry` |
| `charged` | `charge` | | `charged` | append, at most 6 |
| `charged` | `guard_near` | weight >= 40, 20 s in state, no fight, ruler holds no trial | `confront` | `npc_command(guard, 'ArrestPlayer@')`; notify R2 |
| `confront` | `arrest_attempt` | | `confront` | `channel = popup` |
| `confront` | `tick` | 20 s, `channel = ''` | `confront` | `channel = voice`; notify R3 |
| `confront` | `arrest_submit` | | `jailed` | title hold; notify R4; rumour |
| `confront` | `said surrender` | | `jailed` | ScriptProxy `SendPlayerToJail('0x000267EA', true, true)`; title hold; notify R4; rumour |
| `confront` | `arrest_resist` | | `closed` / `resisted` | notify R5; rumour; carry `resist` 300 plus the open weight |
| `confront` | `said pay` or native `bounty_paid` | | `closed` / `paid` | `player.removeitem 0000000F {weight}`, `player.setcrimegold 0 000267EA`, treasury +weight `штраф с ярла`; notify R6 |
| `confront` | `said bribe` | `tesRulerBribe('guard', …)` ok | `closed` / `bribed_guard` | pay if not paid, `player.setcrimegold 0 000267EA`; notify R7 |
| `confront` | `said bribe` | not ok | `confront` | refund; charge `bribery` 200; notify R8 |
| `confront` | `tick` | 180 s | `charged` (`attempts + 1`); at 3 `closed` / `lapsed` | |
| `jailed` | `location` jail | | `jailed` | `confirmed = true` |
| `jailed` | `tick` | not confirmed, 60 s | `charged` (`attempts + 1`) | title restore; notify R10; `ruler_channel = dead` |
| `jailed` | `tick` | confirmed, 180 s of play | `trial` | hold the judge and up to 2 witnesses in the cell; notify R9; `instruct(judge, open)` |
| `trial` | `said guilty` / `said innocent` | | `trial` | `plea` set; note to the one spoken to |
| `trial` | `said bribe` to a witness | `tesRulerBribe('witness', …)` ok | `trial` | `silenced += who`; pay if not paid |
| `trial` | `said bribe` to the judge | ok | `decided`, verdict `acquitted`, mark `bribed_judge` | as `decided` below |
| `trial` | `said bribe` to the judge | not ok | `trial` | refund; charge `bribery` 200; a second refusal forces `decided` |
| `trial` | `said verdict`, or `tick` at 600 s | | `decided` | `tesRulerVerdict()`; `acquitted` or `fine`: `player.setcrimegold 0 000267EA`, fine to the treasury, title restore, free judge and witnesses; `jail`: free them only; notify R13; on the timer one `instruct(judge, verdict)` |
| `decided` (jail) | `said bribe` to a guard | ok | `decided` | `player.additem 0000000A 5`; notify R14 |
| `jailed` `trial` `decided` | `bunk` | | same | `bunk_at = now()` |
| `jailed` `trial` `decided` | `location` not jail | `bunk_at` within 180 s | `closed` / the verdict's outcome, else `served` | title restore; free all; notify R11; rumour |
| `jailed` `trial` `decided` | `location` not jail | no recent bunk | `closed` / `escaped` | title restore; free all; notify R12; rumour; carry `escape` 100 plus half the open weight |
| any open | `impunity_on` | | `closed` / `pardoned` | `player.setcrimegold 0 000267EA`; title restore; free all; notify R15 |
| any open | `tick` | request `gamets` < case `gamets` - 100000 (an older save) | `closed` / `lapsed` | title restore; free all; silent |
| `charged` | `tick` | 3600 s of play | `closed` / `lapsed` | |

"Seconds of play" are counted on `max(eventlog.localts)`, not on the wall clock: a closed game freezes the case.
`free(ref)` is `['prid ref', 'teshold 0', 'tesfollow 0', 'setrestrained 0', 'moveto 000267E5', 'resetai']`, the
sequence `tesCrimeRelease()` uses for released prisoners (`tes_crime/lib.php:379-386`); `000267E5` is the
Whiterun jail's street marker (`tes_crime/lib.php:114`). `hold(ref)` is `['prid ref', 'moveto player', 'tesfollow 0', 'teshold <decimal ref>']`
as the court does (`court.php:294`).

The physical way out of the cell is always vanilla: the bunk (the game returns the inventory, F8) or the lock.
The verdict changes what the bunk costs: cleared cases have the bounty set to 0 first. The player may also use
the bunk before any trial (he did within 12 s in F8): that is `served`.

### Rules (pure, deterministic)

- Judge: `tes_posts.role = 'советник'`, else Провентус Авениччи, Айрилет, Вигнар Серая Грива, Командир Кай,
  else the nearest guard; the first who is alive, adult, not jailed and not a victim in the charges (today the
  list must skip Вигнар, F24).
- `proven` = sum of weights of charges that need no witness (`bounty`, `resist`, `escape`, `bribery`) plus those
  with at least one living, present, unsilenced witness.
- `tesRulerVerdict`: `proven = 0` -> `acquitted`. Plea `guilty`: `proven < 1000` -> fine `proven`; else jail
  `ceil(proven / 2000)` days. Plea `innocent` or none: `proven < 1000` -> fine `2 * proven`; else jail
  `min(7, ceil(proven / 1000))` days. A judge with fear >= 6 (`tesLoyaltyOf`) lowers the verdict one step
  (jail -> fine -> acquitted); a judge with anger >= 6 doubles the fine or the days.
- `tesRulerBribe(whom, amount, case, npc)`: a `decent` nature (`tesCompanionNature`, `companion.php:50-58`)
  always refuses. Guard: ok when `amount >= max(500, 2 * weight)` and anger < 6. Judge: ok when
  `amount >= max(2000, 3 * proven)` and anger < 6, or when fear >= 6 and `amount >= 500`. Witness: ok when
  `amount >= 300`. The player owns 999 500 820 septims (F19): the price never stops him, character and loyalty do.

## B.5 What the player says

| Says | Regex (lower case, `ё` -> `е`) | State | What happens |
|---|---|---|---|
| «Выключи безнаказанность» | existing, `court.php:139` | | the mechanic is armed |
| «Включи безнаказанность» | existing | any | pardon, R15 |
| «Сдаюсь» · «Веди в темницу» · «Арестуй меня» | `(?<![\p{L}])(сдаюсь\|сдаемся\|веди(?:те)?\s+(?:меня\s+)?в\s+(?:темницу\|тюрьму)\|арестуй(?:те)?\s+меня\|я\s+пойду\s+с\s+(?:тобой\|вами))(?![\p{L}])` | `confront` | jail |
| «Плачу штраф» · «Я заплачу штраф» | `(?<![\p{L}])(плачу\|заплачу\|уплачу\|оплачу)\s+(?:\p{L}+\s+){0,2}?штраф\p{L}*\|штраф\p{L}*\s+(?:плачу\|заплачу)` | `confront` | fine to the treasury, closed |
| «Вот тебе 2000, ты ничего не видел» · «Держи 5000 и оправдай меня» · «Даю 3000 за свободу» | an amount from `tesWorldSpokenAmount()` > 0 and `(забудь\|ничего\s+не\s+видел\p{L}*\|отпусти\p{L}*\|закрой\p{L}*\s+глаза\|отвернись\|договоримся\|замнем\|за\s+молчание\|за\s+свободу\|оправдай\p{L}*\|сними\p{L}*\s+обвинени\p{L}*\|отмычк\p{L}*)` | `confront`, `trial`, `decided` | a bribe of the one spoken to, when he is the guard, the judge or a witness of the case |
| «Признаю вину» · «Виновен» | `(?<![\p{L}])(призна[юе]\p{L}*\s+(?:свою\s+)?вину\|виновен\|виновна\|каюсь)(?![\p{L}])` and not `не\s+виновен` | `trial` | plea `guilty` |
| «Я невиновен» · «Это ложь» · «Свидетель лжёт» | `(?<![\p{L}])(не\s*винов(?:ен\|на\|ат)\|это\s+ложь\|клевета\|свидетел\p{L}*\s+лж[еу]т)(?![\p{L}])` | `trial` | plea `innocent` |
| «Выноси приговор» · «Каков приговор?» | `вынос\p{L}*\s+приговор\|каков\s+приговор\|оглас\p{L}*\s+приговор\|решай(?:те)?\s+уже` | `trial` | the verdict now |
| «В чём меня обвиняют?» · «Что мне грозит?» | `в\s+чем\s+(?:меня\s+)?обвиня\p{L}*\|что\s+мне\s+грозит\|какие\s+обвинени\p{L}*` | any open | the one spoken to states the charges |

A phrase outside its state is not consumed: `Невиновен. Иди.` said by the ruler at an NPC's trial stays the
court's (`court.php:328`).

Gold for a bribe: the existing block `preprocessing.php:95-104` already moves the gold when the line has
`вот тебе`, `держи`, `даю`, `плачу` and an amount. The speech part asks P0's `tesStoryGoldMoved($amount)`
(an outbox row with `beat_id = 'tes_world'`, newer than 5 s, containing `player.removeitem 0000000F <amount>`)
and pays only when that is false; a refused bribe is refunded
(`['prid ref', 'removeitem 0000000F N', 'player.additem 0000000F N']`).
Every recognised line sets `$GLOBALS['TES_COURT_NOW'] = true`, so `отпусти меня` never starts an agent task
(`preprocessing.php:163-190`).

Notifications (Russian, verbatim, each under 190 characters):
- R1 `Свидетели донесли страже: {deed}. Безнаказанности нет — стража придёт за тобой.`
- R2 `{guard}: именем закона Вайтрана ты арестован, ярл. Сдайся, плати или дерись.`
- R3 `Скажи стражнику «Сдаюсь» или «Плачу штраф» — или предложи золото.`
- R4 `Ты под стражей в темнице Драконьего Предела. Приказы не исполняются. Суд скоро.`
- R5 `Ты поднял оружие на свою стражу. Холд этого не забудет.`
- R6 `Штраф {n} септимов ушёл в казну. Дело закрыто.`
- R7 `{guard} взял золото и ничего не видел. Дело закрыто.`
- R8 `{who} не берёт золото: к обвинениям добавлен подкуп.`
- R9 `Суд над тобой: судья — {judge}. Скажи «Признаю вину» или «Я невиновен».`
- R10 `Стража не увела тебя: игра не ответила. Дело отложено.`
- R11 `Ты вышел из темницы: {outcome}. Титул и власть при тебе.`
- R12 `Побег из темницы! Стража Вайтрана ищет тебя.`
- R13 `Приговор: {verdict}. Ложись на койку — стража выпустит.`
- R14 `Стражник сунул тебе отмычки и отвернулся.`
- R15 `Безнаказанность возвращена: дело закрыто.`

`{outcome}`: `served` = `срок отбыт`, `acquitted` and `bribed_judge` = `оправдан`, `fined` = `штраф уплачен`.
`{verdict}`: `оправдан` / `штраф {n} септимов в казну` / `темница, {n} дн.`

Rumours (all start with `Говорят, ярл`, so A skips them): `Говорят, ярла {player} взяла под стражу его же стража.`,
`Говорят, ярла судили в его же темнице: {outcome}.`, `Говорят, ярл бежал из собственной темницы.`,
`Говорят, ярл поднял меч на свою стражу.`

Prompt lines (English): judge `You are the judge at the trial of {player}, Jarl of Whiterun, held in his own
jail after he gave up his impunity. Charges: {list}. Witnesses here: {names}. Ask how he pleads. His orders are
not carried out while he is under arrest. The court's rules decide the verdict; you pronounce it when told.`
Witness `You are called as a witness against the Jarl: you saw {deed}. Say what you saw when asked.` Silenced
witness `You took the Jarl's gold. You now say you saw nothing.` Arresting guard `The Jarl gave up his impunity
and is accused of: {list}. You are arresting him; he may surrender, pay or resist.`
Notes appended to the player's line follow the existing form ` *…*` and are English, for example
` *the court's verdict is decided: a fine of 80 septims to the treasury; pronounce exactly this in your own words*`.

## B.6 Parts

### B1 `ext/tes_world/ruler_rules.php` (pure)
```php
tesRulerWeight(string $kind, int $n = 0): int
tesRulerStep(?array $case, array $event, array $ctx): array   // [?array $case, array $effects]
  // $event['t']: charge|guard_near|arrest_attempt|arrest_submit|arrest_resist|bounty_paid|location|bunk|
  //              timeforward|said|impunity_on|tick
  // $ctx = ['player','now','gamets','impunity_off','title','cool_ok','carry','busy','budget_ok',
  //         'npc' => [name => ['ref','alive','child','jailed','nature','fear','anger','present']],
  //         'judges' => [names in order], 'jail_cells' => [names]]
  // effect ops: notify{text} rumor{text} npc_command{npc,command} console{commands}
  //             jail{faction,remove_inventory,real} instruct{npc,text_en} title{mode: hold|restore}
  //             hold{name,ref} free{ref} treasury{delta,why} loyalty{npc,fear,anger} pay{ref,amount}
  //             refund{ref,amount} carry{charge} place{what}
tesRulerVerdict(array $case, array $ctx): array               // ['verdict','fine','days','proven']
tesRulerBribe(string $whom, int $amount, array $case, array $npc): array   // ['ok' => bool, 'why' => string]
```
Acceptance: `php tools/tests/run.php ruler_rules`:
(a) the F8 rows with their real times give `charged -> confront -> jailed -> closed/served`, with `title hold`
exactly once and `title restore` exactly once;
(b) the same start, then ticks at +180 s, `said innocent`, `said verdict`, `bunk`, `location` out give
`jailed -> trial -> decided -> closed`;
(c) leaving without a bunk gives `escaped` and a `carry` effect;
(d) the invariant, for every state and every event type: when the result is `closed`, the effects hold
`title restore` if `held_title <> ''` and one `free` per ref in `held_refs`;
(e) no `hold`, `instruct` or `npc_command` effect ever names an `npc` whose `child` is true, and a dead judge
candidate is skipped;
(f) the verdict rules for 8 cases; a `decent` judge refuses any amount.
Depends on: nothing.

### B2 `ext/tes_world/ruler_events.php` (parsers and cursors)
```php
tesRulerParseEvent(array $row, string $player, array $jailCells): ?array   // pure; $row = type,data,rowid,localts,gamets
tesRulerParseProbe(string $output): ?int                                    // pure
tesRulerPull(): array        // reads eventlog, tes_witness_seen (after the ALTER of B.3), the probe answer;
                             // returns events in order; moves the cursors through P0's wrappers
tesRulerJailCells(string $hold): array   // 'Вайтран' => ['Драконий Предел - Подземелье', 'Подземелье Драконьего предела']
```
Acceptance: `php tools/tests/run.php ruler_events`: the literal rows of F8 and F9 parse to `arrest_submit`,
`location` (jail true), `bunk`, `timeforward` (24.0), `location` (jail false), `charge` (40), `bounty_paid`
(64); `Actor Crime Gold is 200.00` parses to 200; a `location` row of another hold has `jail = false`;
`Шаман uses Трон` is not a bunk. Then, read-only against the live table:
`SELECT rowid, type, data, localts, gamets FROM eventlog WHERE rowid BETWEEN 72030 AND 72080 ORDER BY rowid`
yields `arrest_submit, location, bunk, timeforward, location` in that order.
Depends on: nothing.

### B3 `ext/tes_world/ruler.php` (shell)
Produces: `tesRulerEnsure()`, `tesRulerCase(): ?array`, `tesRulerContext(?array $case): array`,
`tesRulerApply(array $effects, array $case): void`, `tesRulerTick(): void` (every 10 s with an open case, 60 s
without; pulls events, steps, compare-and-set, applies; restores a title left in a closed row).
Acceptance: `php tools/tests/run.php ruler` in dry mode: applying `title hold` then `title restore` logs one
DELETE and one INSERT for `tes_world_titles` carrying the same fact text; `jail` logs one `scriptproxy` effect
with faction `0x000267EA`; `hold` and `free` log the sequences of B.4; `instruct` is dropped when
`tesStoryBudgetOk()` is false or `calls >= 3`.
Depends on: B1, B2, P0.

### B4 `ext/tes_world/ruler_talk.php` (speech and prompt lines)
Produces: `tesRulerPhrase(string $line): array` (pure; `kind` is one of `surrender, pay, bribe, guilty,
innocent, verdict, charges`, plus `amount`; `[]` when none), `tesRulerSpoken(string $line, string $to): string`,
`tesRulerLine(string $me): string`.
Acceptance: `php tools/tests/run.php ruler_talk`: each phrase of B.5 maps to its kind; `Я не виновен` is
`innocent`, `Виновен` is `guilty`; `Суд над Назимом`, `Сдай оружие`, `Заплати Бренуину 300 из казны` map to
`[]`; with no open case `tesRulerSpoken` returns `''` for every phrase; notes have no Cyrillic outside names.
Depends on: B1, B3, P0.

## B.7 In-game test list (owner)

Make a separate save first.
1. `Выключи безнаказанность`. Kill an adult townsman in front of people, away from quest scenes. Within 2
   minutes: R1.
2. Walk to a guard. Expect R2 and the submit/resist window. No window within 20 s: expect R3.
3. Submit (or say `Сдаюсь`). Expect the cell and R4. `Стражник, раздень Назима` does nothing.
4. After 3 minutes: the judge and witnesses stand in the cell, R9. Say `Я невиновен`, then `Выноси приговор`.
5. On a reload of step 4: to a witness `Вот тебе 1000, ты ничего не видел`; to the judge `Держи 5000 и оправдай меня`.
6. Ways out, one per reload: (a) the bunk: R11, `Кто при дворе?` answers again; (b) pick the lock and walk out:
   R12 and a new arrest at the next guard; (c) `Включи безнаказанность` in the cell: R15, then the bunk;
   (d) after a jail verdict, to a guard `Даю 3000, дай отмычки`: R14.
7. After each way out: the judge and the witnesses are back in town, orders work.
8. Tell the builder which of these appeared: the window in step 2; the cell in step 3; the bunk working after
   an acquittal (bounty 0).

---

# C. Shared parts, wiring, operations

## C.1 Part P0 `ext/tes_world/story_io.php` and `tools/tests/run.php` (build first)

Every write of A and B goes through these wrappers; with `$GLOBALS['TES_STORY_DRY'] = true` they append to
`$GLOBALS['TES_STORY_LOG']` and change nothing. This is what makes the shells testable without the game.

```php
tesStoryQueue(array $commands): bool          // -> tesWorldQueue()            log op 'queue'
tesStoryNotify(string $ru): void              // -> tesWatchNotify()           'notify'
tesStoryLetter(string $from, string $title, string $body, int $minutes = 0, string $items = ''): int  // -> tesWorldLetter()  'letter'
tesStoryRumor(string $ru): void               // -> tesGodGuardAddRumor() after tesWorldNeedGuard()   'rumor'
tesStoryNpcCommand(string $npc, string $command): void   // -> tesCrimeNpcCommand()   'npc_command'
tesStoryInstruct(string $npc, string $en): void          // -> tesCrimeTell()         'instruct'
tesStoryJail(string $factionHex, bool $removeInventory, bool $real): void  // ScriptProxy Faction->SendPlayerToJail  'scriptproxy'
tesStoryExec(string $sql): void               // every INSERT/UPDATE/DELETE of the new modules   'sql'
tesStoryGoldMoved(int $amount): bool          // read-only, see B.5
tesStoryBudgetOk(): bool                      // tes_watch.budget_state: left >= 0.30 (watch.php:81)
tesStoryOff(string $which): bool              // tes_watch petitions_off / ruler_off = '1'
tesStoryPlayClock(): int                      // max(eventlog.localts)
tesStoryKin(string $npc): array               // [['name','relation','type','aff','child']] through F21
```
`tools/tests/run.php [name …]` boots like `tools/test_court.php:9-18`, sets dry mode and `PLAYER_NAME`, requires
`lib.php` and whichever story files exist, runs `tools/tests/<name>.test.php` (each gets a `$check(bool, string)`
closure), prints `ALL OK` or `N FAILED`, exits 0 or 1.
Acceptance: `php tools/tests/run.php story_io`: each wrapper logs one entry in dry mode and the row counts of
`skyrim_quest_action_outbox`, `responselog`, `rumors` are unchanged; `tesStoryKin('Карлотта Валентия')` contains
`Мила Валентия` with `child = true` (F16, F21).
Depends on: nothing.

## C.2 Part W: `ext/tes_world/stories.php`, the hook edits, panel, docs (build last, after PLAN P3)

`stories.php` requires the eight files and gives the entry points:
```php
tesStoriesEnsure(): void                       // both CREATE TABLEs
tesStoriesTick(): void                         // tesRulerTick(); tesPetitionTick(); each in its own try/catch
tesStoriesSpoken(string $line, string $to): string   // tesRulerSpoken() first, then tesPetitionSpoken()
tesStoriesLines(string $me): array             // ['ruler' => line, 'petition' => line], empty ones left out
```
Existing files touched, all in one commit:

| # | File | Hook point | Change |
|---|---|---|---|
| 1 | `ext/tes_world/lib.php` | after the last line, `require_once __DIR__ . '/overhear.php';` (1209) | add `require_once __DIR__ . '/stories.php';` |
| 2 | `ext/tes_world/preprocessing.php` | line 20, the array that starts `['tesRealmGatherTick', 'tesErrandTick', …` | append `'tesStoriesTick'` |
| 3 | `ext/tes_world/postrequest.php` | line 96, the array that starts `['tesWorldVerifyTick', 'tesWatchTick', …` | append `'tesStoriesTick'` |
| 4 | `ext/tes_world/preprocessing.php` | line 110, `$tesWorldCourt = tesCourtSpoken($tesWorldLine, $tesWorldAddr);` | replace with `$tesWorldCourt = function_exists('tesStoriesSpoken') ? tesStoriesSpoken($tesWorldLine, $tesWorldAddr) : '';` and `if ($tesWorldCourt === '') { $tesWorldCourt = tesCourtSpoken($tesWorldLine, $tesWorldAddr); }` |
| 5 | `ext/tes_world/context_pre.php` | after the place-memory block that ends at line 176, before `if ($tesWorldHint !== '' …` (177). It must stay outside the `player_title` block of line 132: the judge needs his line while the title is held | `if (function_exists('tesStoriesLines')) { foreach (tesStoriesLines($tesWorldMe) as $k => $l) { chimRegisterPromptInjection('prompt_bottom', 'tes_story_' . $k, $l, 98); } }` |
| 6 | `ext/tes_world/playthrough_tables.txt` | end | `tes_petitions`, `tes_ruler_case` |
| 7 | `tools/ensure_playthrough_tables.php` | after `tesPriceEnsure();` | `tesStoriesEnsure();` |
| 8 | `ext/tes_world/panel.php` | a new section | open petitions; the ruler's case, a held title shown in red |
| 9 | `/tmp/tes_commit.sh` (not in the repo) | after the `test_court.php` line | run `tools/tests/run.php` |
| 10 | `docs/ROADMAP.md`, `docs/in-game-tests.md` | sections 1 and 5 | the phrases of A.6 and B.5, the lists A.8 and B.7 |

New in W: `tools/story_reset.php` prints the open case and the held title; with `--apply` (owner's yes: it
writes) it restores the title, queues `free` for `held_refs` and closes the case.
Acceptance: `php -l` on every file; `tools/test_court.php` still `ALL OK`; `tools/tests/run.php` `ALL OK`; with
no open case and no petition `tesStoriesSpoken()` returns `''` for all 22 lines of `test_court.php:35-56` and
`tesStoriesLines('Назим')` is `[]`; `grep -c tesStoriesTick` gives 1 for `preprocessing.php` and 1 for
`postrequest.php`.
Depends on: all other parts.

A zero-edit alternative exists: a sibling plugin `ext/tes_world_stories/` with its own three hook files is
loaded automatically after `tes_world` (F18). It is not chosen: its speech handler would run after the court
and fast-order chain of the same request and could not pre-empt it.

## C.3 Build order

P0 -> A1, B1, B2 in parallel (pure) -> A2, B3 -> A3, B4 -> W.
New files can be written while the translation worktree is open; W's edits wait for PLAN P3.

## C.4 Cost

| Mechanic | Background model calls | Estimate |
|---|---|---|
| A | 0 | $0. About 60 extra prompt tokens for the petitioner and the subject while a petition is open |
| B | per case: the guard's reaction after `ArrestPlayer` (CHIM's own `funcret`), the judge opening, the judge's verdict on the timer | 3 x $0.003 = $0.009 per case; one case per 20 minutes at most: $0.027 per hour worst case |

Both skip `instruct` when `tesStoryBudgetOk()` is false. Lines the player himself says to the judge, a witness
or a petitioner cost what any line costs today.

## C.5 Assumptions

| # | Assumption | Tag | If false |
|---|---|---|---|
| 1 | A plugin-sent `command\|ArrestPlayer@` opens the submit/resist window | N | 20 s later the case switches to `voice`: R3, `Сдаюсь`, ScriptProxy |
| 2 | ScriptProxy `Faction->SendPlayerToJail` puts the player in the Whiterun cell | N | no jail `location` row in 60 s: R10, the case returns to `charged`; build B-2 (a trial without a cell) |
| 3 | The bunk releases the player when the bounty is 0 | N | cleared verdicts keep the bounty and the player serves it; or try console `servetime` |
| 4 | Deleting the `player_title` row silences all ruler code | R | orders still run in custody: the story is weaker, nothing breaks |
| 5 | `tes_witness_seen` carries `killer` and `witnesses` once witness.php has run | R; the table lacks them today, V | B2 runs the same ALTER first; C1 yields nothing until a death is seen |
| 6 | Letters reach the player in the game | N (table absent, V) | petitions are still announced by notification and listed by voice |
| 7 | Rumour templates keep their Russian keywords after the English-prompt merge | R | the classifier returns `null`; A1's tests fail at once |
| 8 | English relationship keys map to Russian names through `tes_game_index` | V for 4, N in general | the fallback petitioner (witness, adviser, steward) is used |
| 9 | The bounty grows by itself with impunity off while the player stays in `CrimeFactionWhiterun` (F14) | N | C3 stays 0; C1 and C2 still make cases |
| 10 | The Whiterun cell is `Драконий Предел - Подземелье` | V | other holds are out of scope for v1: the title holds only in Whiterun |

## C.6 What can go wrong in production

| Failure | How it is noticed | How it is undone |
|---|---|---|
| The title is not restored after a case | the panel shows a held title with no open case; orders "stop working" | `tesRulerTick` restores it by itself; `Включи безнаказанность`; `tools/story_reset.php --apply` |
| Judge or witness stays held in the cell | `held_refs` not empty on a closed row (panel); the NPC is missing in town | the tick frees them; `story_reset.php --apply` |
| Arrest loop after an escape | more than 3 cases in an hour (panel) | `Включи безнаказанность`; `tes_watch.ruler_off = '1'` |
| An arrest lands in the middle of a vanilla scene | the owner sees it | the window lets him resist; `ruler_off`; the trigger already waits for no fight and no open trial |
| Petition flood, or a petition from the wrong person | panel; `error_log` prefix `[tes_world petition]` | `Хватит прошений`; `tes_watch.petitions_off = '1'` |
| A letter signed by a child, or a child moved to the cell | A1 and B1 tests fail before deploy | not deployable |
| Model spend | `tes_ruler_case.calls`; `tes_watch.budget_state` | `tesStoryBudgetOk()` drops `instruct` |
| Everything | | revert W's commit: the eight files stay on disk and are never called |

## C.7 Could not be determined today

- Whether `command|ArrestPlayer@` from a plugin opens the window (`responselog` keeps 55 rows, none of that kind).
- Whether ScriptProxy `SendPlayerToJail`, console `servetime`, `player.paycrimegold 0 1 000267EA` and
  `getpcmiscstat "Jail Escapes"` work through bridge 16 (none appears in `tes_god_console_log`).
- Whether a bounty accrues with impunity off while the player is a member and ally of the Whiterun crime faction.
- Whether SNQE runs during play (no process at check time).
- The refid of the Whiterun cell door (`tools/cell_refs.py 4A376` would list named refs; not run).
- Whether the tracker-quest commands (F22) show Russian text; a journal entry for an accepted petition is left
  out of v1 for that reason.

## C.8 Found on the way (not part of this design)

- `ext/tes_world/info.php:31` filters children with `race NOT ILIKE '%реб%'`; in this database that filter
  matches nothing (F16), so a child's goals can be sold as a "secret".
- `tools/deploy_tes_world.sh` uses `cp "$SRC"/*` without `-r` and lints only the top-level `*.php` (F18).
