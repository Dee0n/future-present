# Design 13 + 16: plugin state that rolls back with the save, one relationship number

Date 2026-10-09. Roadmap items 13 and 16. Plan row P5. Status: design, nothing built.
Criteria, in order: (1) no core change, (2) correct rollback including updated balances and locked profiles,
(3) live data migrates without loss, (4) small prompt footprint, (5) each part testable without the game.

Paths: `CORE` = `/var/www/html/HerikaServer`, `REPO` = `/home/dwemer/TES-Speech-Adapter`.
"Verified" = I ran a SELECT or read the code on 2026-10-09. "Read" = code read, not executed.

---

## 1. What the platform does (the constraints)

| # | Fact | Source |
|---|---|---|
| F1 | Plugin hooks `preprocessing.php` run before the core handles `init` and `infosave`. | read: `CORE/main.php:201` (hooks), `:1135` (comm.php) |
| F2 | On `infosave` the core copies every NPC row, locked or not, into `core_npc_master_history`, `plugin_extended_data` included, stamped with the save's gamets. | read: `CORE/lib/core/npc_master.class.php:1473-1508`, called at `CORE/processor/comm.php:1346` |
| F3 | On `init` the core deletes and re-inserts NPC rows from the newest history row with `gamets_last_updated <= T`. Rows with `lock_profile=1` are skipped. Order: `gamets_last_updated DESC NULLS LAST, created DESC, history_id DESC`. | read: `npc_master.class.php:1523-1526`, `:1598-1601`, called at `comm.php:225` |
| F4 | Native relationships (`extended_data.relationships`) have their own restore that has no lock filter: it runs for locked NPCs too. Each relationship write snapshots the row (`chimRelationshipTimelineStamp`). | read: `npc_master.class.php:177-235`, `:278-361`, `:1631` |
| F5 | The core also writes history rows between saves: profile regeneration copies the live row with its current `gamets_last_updated` (the last save, unless a relationship write stamped it since). On load, such a row wins the `created DESC` tie and brings plugin data written after the save. | read: `CORE/lib/dynamic_update_util.php:1386`, `CORE/lib/dynamic_profile_scheduler.php:395-407`. Verified in data: gamets 309533984 has 262 history rows for 244 NPCs, gamets 317056576 has 245; 121 history rows carry no `infosave`/`relationship` source |
| F6 | Core skips its rollback when gamets is `10000000` or `$GLOBALS['pgr_skip_rollback']` is set. | read: `comm.php:110`, `:129` |
| F7 | Plugin tables are "unmanaged": playthrough capture and switching never touch them. `core_npc_master` and its history are captured per playthrough. | read: `CORE/lib/playthrough_policy.php:6-10`; verified: 7 playthrough schemas hold 57 tables each, none `tes_*` |
| F8 | Custom `eventlog` types reach the prompt: context queries use deny-lists. | read: `CORE/lib/data_functions.php:928-931`, `:2648-2656` |
| F9 | `setPluginData` replaces one namespace in one UPDATE, creates no history row, changes no timestamp. Generic NPC update paths ignore the column. | read: `npc_master.class.php:438-454`, `CORE/docs/plugin-npc-data.md:17-22` |
| F10 | The relationship block is already in every NPC prompt, with numbers (`RELLLM_CONNECTOR` is empty, so the talking model scores with `#REL:`). `relationships_locked` stops model scoring only; direct writes still work. | read: `CORE/lib/relationship_manager.php:746-836`, `:983-1026`, `CORE/ext/relationship_system/postrequest.php:212-224`; verified: `general_settings` RELATIONSHIP_SYSTEM_ENABLED=true, RELLLM_CONNECTOR='' |

Live data (verified by SELECT, 2026-10-09):

- 242 NPCs, 28 with `lock_profile=1`, `plugin_extended_data` empty for all, every NPC has history rows.
- 50 NPCs carry a `[Помнит]` block in `npc_static_bio` (15 507 chars in total, longest 1 296); 27 of them are locked, so their memory never rolls back today.
- `tes_loyalty`: 8 rows, all resolve to an NPC id by exact name, 5 of the 8 are locked. By the wall-clock rule (1 point per hour, last write 2026-10-06) every value is 0 today; computed from the rows, not run through `tesLoyaltyOf`.
- `tes_companion`: the table does not exist (no companion yet). `tes_npc_worn`: 29 rows, 21 resolve to an NPC id.
- 53 NPCs have `relationships.Player`; keys in use: `aff`, `type`, `note`, `worst`, `worst_delta`, `best`, `best_delta`. 16 NPCs have `relationships_locked`.
- Treasury: balance 9 658 = sum of 32 ledger rows.
- History: 25 621 rows, 418 MB, about 1.3 MB per save. Abandoned-timeline rows exist: history reaches gamets 319 824 864, the event log ends at 307 609 696.
- The 8 latest `init` rows in the event log each sit 65 to 3 905 gamets above an `infosave` row (the loaded save).
- Game clock: 1 028 gamets per real second (last 25 minutes of the log), so 1 real hour = 3.7 M gamets.
- `PLAYTHROUGH_AUTO_SWITCH=true`, two characters linked to playthroughs 6 and 7.
- No foreign keys and no triggers on any `tes_*` table.

Consequence of F3 + F5: the native API alone fails criterion 2 twice. Locked rows are never restored, and
unlocked rows can be restored from a history row that holds plugin data newer than the save. The design
must close both holes from the plugin side.

---

## 2. Approaches

### A. Native stamped store + snapshot carrier (recommended)

Per-NPC state lives in `plugin_extended_data` namespaces. Every value carries `g` (gamets of the write)
and `w` (unix time of the write). After a load the plugin runs one UPDATE that replaces every value
"from the future" (`g > T`, written before the load) with the value from the history row the core itself
would pick, skipping history rows whose value is also newer than `T`. That covers locked rows (F3) and
leaked rows (F5). World tables stay as they are; a generic snapshotter writes them as JSON into
`plugin_extended_data` of one service NPC row on `infosave` (F1, F2 copy it into history) and restores them
on `init`. Trust and anger move into native affinity (F4 rolls it back for locked NPCs too).

- Cost: one new plugin (`ext/tes_state`, about 700 lines), a 3-line change at each of about 12 call sites, no change in the other 30 files that use world tables.
- Cheapest failure: the core treats the service row as an NPC (it shows in the NPC manager; the owner deletes it). Effect: loads made before the next save find no snapshot and leave the world untouched, with a notice.
- Best when: playthroughs switch (they do, auto-switch is on), locked profiles matter (5 of 8 loyalty rows, 27 of 50 memories), and the platform keeps copying `plugin_extended_data` into history (documented, `plugin-npc-data.md:22`).

### B. Plugin-owned snapshot tables

On `infosave` copy every gameplay table (per-NPC tables too) into `tes_state_snap(gamets, table, rows)`;
on `init` restore the newest snapshot `<= T`. Per-NPC state stays keyed by name in `tes_*` tables.

- Cost: about 250 lines, zero call-site changes. The cheapest to build.
- Cheapest failure: playthrough auto-switch. Snapshots are not captured (F7), so character 7 loading a save restores a snapshot of character 6 with a lower gamets. Keying by playthrough id fixes the common case and still loses dragon-break copies. The `[Помнит]` block in the bio stays unrolled for the 27 locked NPCs, and item 16 is untouched.
- Best when: one playthrough forever and the native API were not asked for.

### C. Ledger rows pruned by gamets (the core's own rule)

Every write becomes an append-only row with gamets; balances are sums, flags are "latest row wins";
on `init` delete `gamets >= T` as `comm.php:130-150` does. A variant stores the rows in `eventlog`.

- Cost: rewrite of every reader and writer of 30 tables (at least 28 distinct UPDATE statements on 9 of the world tables, by grep).
- Cheapest failure: one missed UPDATE site is a silent memory of the future. State machines (`tes_court.closed`, `tes_crime_jail.stage`, `tes_order_checks.stage`) have no ledger form. The `eventlog` variant leaks rows into prompts (F8).
- Best when: state is a few additive balances. Only the treasury is (its ledger already equals the balance), and A reuses that.

### D. Everything inside native relationships

Put trust, fear, anger as extra keys into `extended_data.relationships.Player`; F4 restores it for locked rows with no plugin code.

- Cost: smallest. Each write adds a 5.5 KB history row.
- Cheapest failure: the relationship editor and the "rebuild" path rewrite the entry and drop unknown keys (`relationship_manager.php:337-367`, `replaceExisting`). No place for memory lines, companion state or the world.
- Best when: affinity is the only state. A takes exactly that part: one number, written through the native path.

### Recommendation: A

Against the criteria: (1) A, B, C, D change no core file. (2) Only A rolls back locked profiles for all
state and survives playthrough switches; B fails on switches, C on updated rows, D covers one number.
(3) A migrates 50 memory blocks and 8 loyalty rows with a backup table and a reverse tool (section 6).
(4) A removes two prompt clauses and adds none (section 5). (5) A's logic is SQL builders and pure
functions, tested on session-local temporary tables. A also reuses the most platform: save snapshot,
history, playthrough capture, relationship timeline.

Deviation from the roadmap text: "what is worn" stays a cache table and is cleared on load. It is an
observation with a 15-minute life, 8 of 29 rows have no NPC id, and it is rewritten on every talk.

---

## 3. Data contract

### 3.1 Envelope (every per-NPC namespace)

```json
{ "v": 1, "g": 307098784, "w": 1791300000, "...": "payload" }
```

- `g` int: game time of the last write. `0` = baseline written by the migration; never rolled back.
- `w` int: unix seconds of the last write.
- Namespaces are replaced whole (F9). One writer function per namespace. Never call `deletePluginData` on them; write an empty payload instead.

### 3.2 Namespaces

| Namespace | On | Payload | Limits |
|---|---|---|---|
| `tes_mood` | any NPC | `"fear": 4.5, "fear_g": 307098784` | fear 0..10; value is as of `fear_g`; reader subtracts `(now_g - fear_g) / 3700000` |
| `tes_companion` | companions | `"join_aff": 20, "join_g": 0, "grudges": ["казнь: Хеймскр"], "goal": "...", "task_mark": 178, "court_mark": 3, "left_g": null, "asked_w": 0` | grudges max 5; goal max 160 chars |
| `tes_memory` | any NPC | `"lines": [ {"t": "Видел своими глазами: ...", "g": 307000000} ]` | max 8 lines, 300 chars each, oldest first |
| `tes_world` | service row only | see 3.4 | log a warning above 512 KB |

Not stored any more: trust and anger. They are `extended_data.relationships.Player.aff` (section 5).

### 3.3 Tables

New, never rolled back, never snapshotted:

```sql
CREATE TABLE IF NOT EXISTS public.tes_state_meta (
  key text PRIMARY KEY,
  value jsonb NOT NULL DEFAULT '{}'::jsonb,
  updated_at timestamptz NOT NULL DEFAULT now());
```

Keys: `horizon` `{"T":int,"w":int,"world":"restored|none|stale|skipped","npc_rows":int,"tables":{...}}`,
`undo_world` (snapshot JSON taken just before the last restore), `undo_npc` `[{"id":int,"ns":str,"value":obj|null}]`,
`migrated` `{"at":str,"memory":int,"mood":int,"aff":int}`.

Service row in `core_npc_master`: `npc_name='tes_world_state'`, `lock_profile=1`,
`npc_static_bio='Служебная запись плагина tes_state: снимки мира для отката с сейвом. Не удалять.'`.
Created with `INSERT ... ON CONFLICT (npc_name) DO NOTHING`. Looked up by name every time (the id changes if the owner deletes it).

Migration backups (section 6): `public.tes_backup_state13_npc(id int PRIMARY KEY, npc_name text, npc_static_bio text, plugin_extended_data jsonb, relationships jsonb, saved_at timestamptz)`, `public.tes_backup_state13_loyalty` (copy of `tes_loyalty`).

### 3.4 World snapshot (`tes_world`)

```json
{ "v": 1, "g": 307098784, "w": 1791300000,
  "tables": {
    "tes_court":        { "mode": "full", "rows": [ { "id": 3, "defendant": "...", "closed": false } ] },
    "tes_crime_jail":   { "mode": "open", "mark": 45, "rows": [ { "id": 45, "status": "jailed" } ] },
    "tes_treasury_log": { "mode": "tail", "mark": 32 },
    "tes_prices":       null } }
```

`null` = the table did not exist at the save (restore empties it). A manifest table missing from `tables` = unknown at the save (restore leaves it alone).

Manifest (`ext/tes_state/world_tables.php`, returns `table => [mode, pk, open?, scope?]`):

| Mode | Snapshot | Restore | Tables |
|---|---|---|---|
| `full` | all rows in `scope` | delete rows in `scope`, insert rows | `tes_court`, `tes_craft_orders`, `tes_crime_fines`, `tes_documents`, `tes_errands`, `tes_estate_sales`, `tes_festival`, `tes_gatherings`, `tes_legends`, `tes_mutes`, `tes_order_checks`, `tes_place_memory`, `tes_posts`, `tes_prices`, `tes_rumor_spread`, `tes_sanguine_bets`, `tes_treasury`, `tes_undo`, `tes_agent_undo`, `tes_witness_seen`, `tes_world_duels`, `tes_world_facts`, `tes_world_letters`, `tes_world_titles`, `tes_watch` (scope below) |
| `open` | `mark = max(id)` + rows matching `open` | delete `id > mark`, upsert rows by pk | `tes_crime_jail` (`status <> 'released'`), `tes_world_patrols` (`stage <> 'done'`), `tes_fest_queue` (`NOT done`) |
| `tail` | `mark = max(id)` | delete `id > mark` | `tes_treasury_log`, `tes_agent_tasks` |
| `clear` | nothing | delete all rows | `tes_npc_worn`, `tes_talk_hold`; plus `tes_watch` keys `worn_ask_%`, `worn_dirty`, and `worn_last_id` set to `max(id)` of `tes_god_console_log` |
| not listed | never touched | never touched | `tes_god_console_log`, `tes_god_guard_log`, `tes_game_index`, `tes_ui_tr`, `tes_backup_*`, `tes_state_meta`, `tes_loyalty` and `tes_companion` (retired) |

`tes_watch` scope (gameplay keys): `key ~ '^(court_|tax_rate$|mute_all$|party_|stripped_|rags_|sheo_|gods_bet|god_favor_|god_asked_|sanguine_|clavicus_|player_nick$|plot_names$|npc_prayer$)'`.
Everything else in `tes_watch` (budget, bridge, chatter backups, impunity, rate-limit marks, `witness_rowid`) is global. Classified by key name; the builder confirms each key at its `tesWatchSet` call and leaves doubtful keys global.

Restore rules (all tables in one transaction):

1. Time shift: every `timestamptz` value of an inserted row gets `+ (now - snapshot.w)` seconds. Timers written as `now() + interval` keep the remaining time they had at the save (a letter due in 10 minutes is due in 10 minutes after the load).
2. Column drift: insert only columns present both in the snapshot row and in the table today; the rest take their defaults.
3. Sequences are never reset. Ids stay monotonic, so `mark` comparisons and the companion's `task_mark`/`court_mark` stay valid.
4. `open` assumes closed rows are final. Read: `released`, `done`, `done=true` are set and never unset in `ext/tes_crime`, `ext/tes_world`. `tes_gatherings.released` flips back, so it is `full`.
5. Known gap: a `tes_agent_tasks` row that was open at the save keeps the status it got later.

Snapshot size today: 58 KB for all tables in `full` (verified by SELECT), an estimated 16 KB with the modes above (jail, patrols and the ledger stop being copied whole). Added to 1.3 MB per save that the core already writes.

---

## 4. Rollback procedure

### 4.1 Per-NPC fix-up (the load-bearing statement)

`T` = gamets of the `init` request, `W` = unix time when that request arrived. One statement per namespace
in `['tes_mood','tes_companion','tes_memory']`. `{S}` is the schema (`public`; tests pass `pg_temp`).

```sql
WITH cur AS (
  SELECT c.id FROM {S}.core_npc_master c
  WHERE c.plugin_extended_data ? '{ns}'
    AND COALESCE((c.plugin_extended_data #>> '{{ns},g}')::numeric, 0) > {T}
    AND COALESCE((c.plugin_extended_data #>> '{{ns},w}')::numeric, 0) < {W}
), pick AS (
  SELECT cur.id, (
    SELECT h.plugin_extended_data -> '{ns}'
    FROM {S}.core_npc_master_history h
    WHERE h.npc_id = cur.id
      AND (h.gamets_last_updated <= {T} OR h.gamets_last_updated IS NULL)
      AND COALESCE((h.plugin_extended_data #>> '{{ns},g}')::numeric, 0) <= {T}
    ORDER BY h.gamets_last_updated DESC NULLS LAST, h.created DESC, h.history_id DESC
    LIMIT 1) AS val
  FROM cur
), upd AS (
  UPDATE {S}.core_npc_master c
  SET plugin_extended_data = CASE WHEN pick.val IS NULL THEN c.plugin_extended_data - '{ns}'
      ELSE jsonb_set(c.plugin_extended_data, ARRAY['{ns}'], pick.val, true) END
  FROM pick WHERE c.id = pick.id
  RETURNING c.id)
SELECT count(*)::int AS affected FROM upd
```

Properties: same row order as the core (F3); skips leaked history rows (F5); works on locked rows; removes
the namespace when the NPC had none at the save; leaves values written after the load (`w >= W`) and
baseline values (`g = 0`) alone; a second run changes 0 rows. Before the UPDATE the caller selects the
rows of `cur` with their current values into `tes_state_meta.undo_npc`.

### 4.2 World restore

1. `g_save` = `max(gamets_last_updated)` over history rows with `gamets_last_updated <= T` and `extended_data->>'_chim_history_source' = 'infosave'`.
2. Take the service row's history row with `gamets_last_updated = g_save` (by `npc_id` when the live row exists, else by `npc_name`).
3. The snapshot is valid only when `tes_world.g = g_save`: it was taken in the request of the save being loaded. No row = `none`; mismatch = `stale` (the snapshot hook failed or the plugin was off at that save). In both cases world tables are left as they are and the player is told once: `Откат мира: для этого сейва нет снимка, состояние двора и казны оставлено как есть`.
4. Valid: store the current state as `undo_world`, restore per 3.4, then tell the player: `Сейв загружен: двор, казна и приказы возвращены к моменту сохранения`.
5. `clear` tables are emptied on every accepted `init`, snapshot or not.

### 4.3 Lifecycle (`ext/tes_state/preprocessing.php`)

| Request | Action |
|---|---|
| type starts with `infosave`, gamets numeric and > 0 | build the snapshot with `g` = request gamets, write it with `NpcMaster::setPluginData(serviceId,'tes_world',...)`. The core copies it into history later in the same request (F1, F2). Failure: `error_log`, notice `Снимок мира для сейва не записан` at most once an hour. |
| `init`, and not (gamets `10000000`, not numeric, `<= 0`, or `pgr_skip_rollback` set) (F6) | `W = time()`; write `horizon`; write pending file `sys_get_temp_dir()/tes_state.pending` = `{"T":..,"w":..}`; run 4.1; run 4.2 once for this `(T, W)`. |
| any other request while the pending file exists | run 4.1 again with the stored `T`, `W` (the core's restore finishes after our first run and can re-introduce leaked values, F5). Delete the file when it is older than 90 s. |
| any request, file `ext/tes_state/DISABLED` exists | do nothing (kill switch). |

Cost outside the 90 s window: one `is_file` per request.

---

## 5. One relationship number (item 16)

Today four numbers describe how an NPC stands to the ruler: native `aff`, plugin `trust`, `fear`, `anger`.
Live contradiction (verified): Бренуин has `aff +80, grateful` and a stored anger of 3 ("ты затаил обиду").

Rules:

1. `aff` (native, -100..100) is the only relationship number. Deeds move it through one writer, `tesBondApply`.
2. The writer changes `aff`, `note`, and `worst`/`worst_delta` or `best`/`best_delta` (when the delta beats the stored one) in one `NpcMaster::updateByArray` inside `chimRunWithRelationshipExtendedDataWrite`, then calls `chimRelationshipTimelineStamp($id)` once. Same sequence as `RelationshipManager::setRelationship` (`relationship_manager.php:1011-1020`), with the note inside the snapshot. It never changes `type`. It ignores `relationships_locked` (the lock is against model scoring, F10).
3. Deltas: `aff delta = -round(5 x old anger bump)`; companion weights keep their numbers.

| Deed | Victim: aff / fear | Hand that did it | Watching companion: decent / cruel / plain |
|---|---|---|---|
| kill | -10 / +4.0 | -3 / +0.3 | -8 / +3 / -3 |
| jail | -15 / +3.0 | -3 / +0.3 | -3 / +1 / -1 |
| strip | -8 / +2.0 | -3 / +0.3 | -6 / +1 / -3 |
| beg | -15 / +1.5 | -3 / +0.3 | -3 / +1 / -1 |
| take | -13 / +1.0 | -3 / +0.3 | -4 / +3 / -1 |
| bring | -2 / +0.5 | none | none |
| free | none | none | +4 / -2 / +1 |
| fine | none | none | 0 / +1 / 0 |
| feast | none | none | +1 when the nature's feast weight is positive |
| tax raised (trader) | -8 / +0.5 | | |

Court verdicts seen by a companion count half (`intdiv`), as in `companion.php:137`.

4. No double scoring: skip the `aff` delta (fear still applies) when the NPC is the speaker of this request (`HERIKA_NAME`) and is not `relationships_locked`; the model scores its own reaction with `#REL:`. Constant `TES_BOND_SKIP_SELF_SCORED = true`.
5. Fear stays a short mood (`tes_mood`), fading 1 point per 3.7 M gamets. It is not a relationship: a devoted NPC can be terrified.
6. Companion: at joining store `join_aff`. He leaves when `aff <= join_aff - 40` or `aff <= -35`. He asks for help with his goal only when `aff >= join_aff - 15`.
7. Plotters (`realm.php:473`): NPCs with `relationships.Player.aff <= -56` (tier Resentful or worse), worst first, limit 5.
8. Kill switch: `TES_BOND_NATIVE = false` keeps fear and skips every `aff` write.

Notes written into the native entry (the model reads them in the relationship block; English, max 60 chars):
`stripped in public on the ruler's order`, `jailed on the ruler's order`, `robbed of everything on the ruler's order`,
`driven out to beg on the ruler's order`, `made to carry out the ruler's order`, `watched the ruler have {name} executed`,
`watched the ruler pardon {name}`, `the ruler raised the taxes`.

Prompt lines (English; they replace the Russian lines of `watch.php:242-257` and `companion.php:176-198`):

| When | Line |
|---|---|
| fear >= 6 | `Right now you are terrified of the ruler: you tremble, plead and obey almost without a word.` |
| 3 <= fear < 6 | `Right now you fear the ruler's temper and obey without arguing.` |
| companion | `You travel with the ruler as his companion. What still stings: {grudges}. Your own goal: {goal}. When you disagree with him, say so in character.` (the grudges sentence only when there are any) |
| companion who left | `You left the ruler's company because you stopped trusting him ({grudges}). You come back only if he truly makes amends.` |
| memory | appended to `$GLOBALS['HERIKA_PERS']` as `[MEMORIES]` + one `- {t}` line per entry (same place in the prompt as the bio block today; the relationship plugin appends there too, `CORE/ext/relationship_system/context_pre.php:192`) |

Footprint: both anger clauses and the trust-level clause go away (the native block already prints tier and reason). Nothing is added.
Player-facing text stays Russian and unchanged: `{npc} больше не идёт с тобой: доверие исчерпано`, the farewell letter, the rumour.

---

## 6. Migration and its rollback

Tool: `REPO/tools/migrate_state_13.php`. Needs the owner's yes (it changes data). Run with the game closed.

`--dry-run` (default): prints per-NPC what would move; writes nothing.

`--apply`, one transaction:

1. Refuse when `tes_backup_state13_npc` exists.
2. Create both backup tables (3.3) for every NPC that has a `[Помнит]` block or a `tes_loyalty` row.
3. Memory: split the bio at `"\n\n[Помнит]\n"` (the writer's marker, `tes_god_guard/functions.php:447`); lines become `tes_memory` with `g = 0`; the bio keeps the text before the marker.
4. Loyalty: for each row take the faded values (`tesLoyaltyOf`). `fear >= 0.5` becomes `tes_mood` (`g = 0`, `fear_g` = current gamets). `anger >= 0.5` becomes one `tesBondApply(id, -round(5 x anger), 'carried over from the old anger score')`. Today all 8 rows are at 0, so this writes nothing.
5. `tes_companion`: same shape if the table exists (it does not today).
6. Insert the service row. Write `tes_state_meta.migrated`.
7. Verify before COMMIT: per NPC, line count in `tes_memory` = line count parsed from the backup bio, and new bio = backup bio up to the marker. Any mismatch: ROLLBACK, exit 1.

Why `g = 0`: the first load after the migration targets a save older than the migration. A real stamp would mark every migrated value as "future" and, with no history to fall back on, delete it.

`--rollback`, one transaction: rebuild the `[Помнит]` block in each bio from the current `tes_memory` lines (lines added after the migration survive); where the namespace is gone, restore the bio from the backup; restore `relationships` from the backup for NPCs touched in step 4; remove the three namespaces; refill `tes_loyalty` from its backup; delete the service row and `migrated`; keep the backup tables. Then `touch CORE/ext/tes_state/DISABLED` and revert the Part 7 commit.

Saves made before the migration: unlocked NPCs come back from old history with the block in the bio and no namespace. The reader (Part 5) sees the marker and migrates that NPC on the spot with `g = 0`. World tables have no snapshot for those saves and are left alone (4.2 step 3).
Memories of the future that are already in the live tables (the owner went from gamets 319.8 M back to 305.8 M on 2026-10-06) cannot be removed: no snapshot of that moment exists.

---

## 7. Parts

Build order: 1, then 2, 4, 5 in parallel, then 3 and 6, then 7. Parts 1 to 6 create only new files, so they
do not collide with the open translation worktree (`PLAN-2026-10-09.md`, constraints). Part 7 edits existing
files and waits for plan row P3.

Every test: `runuser -u www-data -- php REPO/tools/<test>.php` inside the distro; from Windows prefix
`MSYS_NO_PATHCONV=1 wsl -d DwemerAI4Skyrim3 -u root --`. Pass = last line `ALL OK` and exit code 0.
Tests write only to `CREATE TEMP TABLE` copies (session-local, gone when the script ends) and never call
the core's `restoreNPC` (it updates `public.core_npc_master` by full name, `npc_master.class.php:1643-1681`).

### Part 1. Stamped per-NPC store and fix-up

- Files: `ext/tes_state/manifest.json`, `ext/tes_state/lib.php`, `tools/test_state_lib.php`, `tools/test_state_npc.php`.
- Contract:
  - `tesStateNow(): int` request gamets when numeric, > 0 and not 10000000; else `DataLastKnownGameTS()`.
  - `tesStateNpcId(string $name): int` exact `npc_name`, then case-insensitive; 0 when none.
  - `tesStateGet(int $npcId, string $ns): array` `[]` when absent; nested values as arrays.
  - `tesStatePut(int $npcId, string $ns, array $payload, ?int $g = null): bool` adds `v`, `g`, `w`; calls `NpcMaster::setPluginData`.
  - `tesStateRollbackSql(string $ns, int $T, int $W, string $schema = 'public'): string` section 4.1.
  - `tesStateRollback(int $T, int $W, string $schema = 'public'): int` stores `undo_npc`, runs all namespaces, returns rows changed.
  - `tesStateMetaGet(string $key): array`, `tesStateMetaSet(string $key, array $value): void`, `tesStateEnabled(): bool`.
  - `lib.php` ends by loading `world.php`, `lifecycle.php`, `bond.php`, `memory.php`, `companion_state.php` when each file exists.
  - `tools/test_state_lib.php`: `tesTestTempCore(): void` creates temp `core_npc_master`, `core_npc_master_history` (`LIKE public...`, primary key and unique name index, temp sequence for `history_id`), `tes_state_meta`; `tesTestCheck(bool, string)`; `tesTestDone()`.
- Acceptance: `php tools/test_state_npc.php`. Cases: stamps added; other namespaces kept; locked row with a future value gets the save's value; leaked value (history row with the save's gamets, later `created`, newer `g`) is skipped; no eligible history removes the namespace; `g = 0` kept; `w >= W` kept; second run returns 0; `undo_npc` holds the old values; core contract check (`method_exists(NpcMaster,'setPluginData')`, history has column `plugin_extended_data`, `npc_master.class.php` still lists it in `backupAllNpcs`).
- Depends on: nothing.

### Part 2. World snapshot engine

- Files: `ext/tes_state/world.php`, `ext/tes_state/world_tables.php`, `tools/test_state_world.php`.
- Contract:
  - `tesWorldManifest(): array` section 3.4.
  - `tesWorldSnapshot(int $g, string $schema = 'public'): array` skips missing tables (writes `null`).
  - `tesWorldRestore(array $snap, int $nowW, string $schema = 'public'): array` per-table `['deleted'=>n,'inserted'=>n]`; one transaction; throws and changes nothing on any error.
  - `tesWorldClear(string $schema = 'public'): void` the `clear` mode.
  - `tesWorldServiceId(bool $create): int`, `tesWorldSnapStore(array $snap): bool`, `tesWorldSnapLoad(int $T): array` `['state'=>'ok|none|stale','snap'=>?array,'g_save'=>int]` section 4.2 steps 1 to 3.
- Acceptance: `php tools/test_state_world.php`. Cases on temp copies of `tes_court`, `tes_treasury`, `tes_treasury_log`, `tes_crime_jail`, `tes_watch`: balance changed after the snapshot comes back; ledger rows after the mark go; a jail row released after the snapshot is `jailed` again; a `tes_watch` global key survives; a `due_at` 600 s ahead at the snapshot is 600 s ahead after a restore one hour later; a column added after the snapshot takes its default; `null` table is emptied; unknown table untouched; a broken row rolls the whole restore back; `SnapLoad` returns `stale` when `g` differs from the save's gamets.
- Depends on: Part 1.

### Part 3. Save and load hooks

- Files: `ext/tes_state/preprocessing.php`, `ext/tes_state/lifecycle.php`, `tools/state_admin.php`, `tools/deploy_tes_state.sh`, `tools/test_state_hooks.php`.
- Contract:
  - `tesStatePlan(array $gameRequest, bool $skipRollback, ?array $pending, int $now): array` pure; returns steps from `['snapshot','horizon','npc_fix','world_restore','clear','drop_pending']` per section 4.3.
  - `tesStateOnSave(int $g): void`, `tesStateOnLoad(int $T): void`, `tesStateOnTick(): void`.
  - `tools/state_admin.php --status | --undo-world | --undo-npc | --snapshot-now` (`--undo-*` re-apply `tes_state_meta.undo_*`).
  - `deploy_tes_state.sh`: `php -l` every file, then copy to `CORE/ext/tes_state/`, same shape as `tools/deploy_tes_world.sh`.
- Acceptance: `php tools/test_state_hooks.php`. Cases for `tesStatePlan`: `infosave` and `infosave_auto` give `snapshot`; `init` with 10000000, with `0`, with text, with `skipRollback` give `[]`; valid `init` gives `horizon,npc_fix,world_restore,clear`; `request` with a 30 s old pending gives `npc_fix`; with a 91 s old pending gives `npc_fix,drop_pending`; `DISABLED` file gives `[]`. One end-to-end case on temp tables: save, change balance and a locked NPC's fear, `init` with the save's gamets + 100, both are back.
- Depends on: Parts 1, 2.

### Part 4. Bond: native affinity writer and mood

- Files: `ext/tes_state/bond.php`, `tools/test_state_bond.php`.
- Contract:
  - `tesBondDeedDelta(string $kind, string $role, string $nature = 'plain'): array` `['aff'=>int,'fear'=>float]`, roles `victim|hand|witness|trader` (table in section 5).
  - `tesBondMerge(array $rel, int $delta, string $note): array` pure; clamps -100..100; sets `note`; updates `worst`/`best` per rule 2; keeps `type` and unknown keys.
  - `tesBondShouldScore(array $npcRow, string $speaker): bool` rule 4.
  - `tesBondApply(int $npcId, int $delta, string $note): bool` rule 2.
  - `tesBondAff(int $npcId): int`.
  - `tesMoodFear(int $npcId, ?int $nowG = null): float`, `tesMoodBump(int $npcId, float $fear): void` (cap 10), `tesMoodLine(float $fear): string`.
  - `tesBondDeed(string $npcName, string $kind, string $role, string $nature = 'plain', string $about = ''): void` resolves the id, applies fear, applies `aff` when rule 4 allows.
- Acceptance: `php tools/test_state_bond.php`. Cases: every table cell; merge clamps and keeps `type`; a smaller negative delta does not replace `worst`; fear 6 at `fear_g` reads 4 after 7.4 M gamets and 0 after 30 M; lines at 2.9, 3, 6; speaker and unlocked gives no `aff` write, speaker and locked gives one; on temp tables one `tesBondApply` adds exactly one history row whose `relationships.Player.note` equals the note.
- Depends on: Part 1.

### Part 5. Memory and companion state

- Files: `ext/tes_state/memory.php`, `ext/tes_state/companion_state.php`, `ext/tes_state/context_pre.php`, `tools/test_state_memory.php`.
- Contract:
  - `tesMemoryParseBio(string $bio): array` `['base'=>string,'lines'=>string[]]` pure.
  - `tesMemoryLines(int $npcId): array` strings, oldest first; migrates a legacy bio block on first sight (`g = 0`).
  - `tesMemoryAdd(int $npcId, string $text): array` trims to 300 chars, applies the hygiene rules by calling `tesGodGuardHygieneLines` when it exists, dedupes, keeps the last 8, returns the lines.
  - `tesMemoryBlock(int $npcId): string` `''` or `[MEMORIES]` block.
  - `tesCompanionLoad(int $npcId): array` (defaults filled), `tesCompanionSave(int $npcId, array $state): bool`, `tesCompanionLeft(array $state, int $aff): bool` rule 6, `tesCompanionLineEn(array $state): string`.
  - `context_pre.php`: for `HERIKA_NAME` other than the Narrator, append `tesMemoryBlock` to `$GLOBALS['HERIKA_PERS']` and register the fear line as `chimRegisterPromptInjection('prompt_bottom','tes_state_fear',...,96)`.
- Acceptance: `php tools/test_state_memory.php`. Cases: parse of a bio with and without the block, with the block in the middle of blank lines; 9 adds keep 8; duplicate dropped; legacy bio migrates once and the bio loses the block; block text matches the lines; `tesCompanionLeft` at `join_aff 20`: false at -19, true at -20; at `join_aff 0`: false at -34, true at -35; a namespace written with `g` above the save disappears after `tesStateRollback` and the save's lines return.
- Depends on: Part 1; Part 4 for `tesBondAff`.

### Part 6. Migration tool

- Files: `tools/migrate_state_13.php`, `tools/test_state_migrate.php`.
- Contract: section 6. Functions `tesMigratePlan(string $schema): array`, `tesMigrateApply(string $schema): array`, `tesMigrateRollback(string $schema): array`, each returning counts `['memory'=>n,'mood'=>n,'aff'=>n,'errors'=>[]]`.
- Acceptance: `php tools/test_state_migrate.php` runs apply then rollback on a temp copy of the live `core_npc_master` and `tes_loyalty` (`CREATE TEMP TABLE ... AS SELECT * FROM public...`). Pass when: 50 NPCs migrated (or the live count printed by `--dry-run`), every bio after rollback equals the bio before apply byte for byte, `tes_loyalty` copy is identical, no namespace is left. Then `php tools/migrate_state_13.php --dry-run` prints the same counts and exits 0.
- Depends on: Parts 1, 4, 5.

### Part 7. Switch the callers (after plan row P3)

- Files (existing): `ext/tes_world/companion.php`, `watch.php`, `info.php`, `context_pre.php`, `realm.php`, `lib.php`, `panel.php`, `playthrough_tables.txt`, `ext/tes_god_guard/functions.php`; new `tools/test_state_integration.php`.
- Contract: public function names and signatures stay; bodies delegate.
  - `tesLoyaltyBump($npc, $fear, $anger)` calls `tesMoodBump` and `tesBondApply(-round(5 x anger))`; the call sites at `lib.php:1004-1013` and `realm.php:606` move to `tesBondDeed`.
  - `tesLoyaltyOf` returns `['fear'=>tesMoodFear, 'anger'=>0.0]`; `tesLoyaltyLine` returns `tesMoodLine`; the injection at `context_pre.php:163-168` is removed (Part 5 registers it).
  - `tesCompanionTick` and `tesCompanionLine` read and write through `tesCompanionLoad/Save`, move trust with `tesBondDeed(..., 'witness', $nature)`, leave by `tesCompanionLeft`; `tesCompanionEnsure` stops creating the table.
  - `tesGodGuardRemember` calls `tesMemoryAdd`; `tesInfoSecret` reads `tesMemoryLines`; `tesGodGuardSetRelation` and the `.relation` verb write the note through `tesBondMerge` before the stamp (today the note is written after it, `functions.php:555`, `:925`, and misses the snapshot).
  - `realm.php:473` uses rule 7. `panel.php:61-62` reads companions from `plugin_extended_data`.
  - `playthrough_tables.txt` is replaced by a two-line pointer to `ext/tes_state/world_tables.php`.
- Acceptance: `php tools/test_state_integration.php` (remember, read back, roll back on temp tables; a strip order gives the victim fear 2 and `aff` -8; the companion line is English and has no trust word); `php tools/test_court.php` prints ALL OK; `php tools/test_ext.php` stays at 97 passed, 2 old failures; `grep -rn "tes_loyalty\|tes_companion\|\[Помнит\]" ext --include=*.php` shows matches only in comments and in `ext/tes_state/memory.php`.
- Depends on: Parts 4, 5; deploy together with the Part 6 `--apply`.

---

## 8. Assumptions

| # | Assumption | Status | If false |
|---|---|---|---|
| A1 | Plugin `preprocessing.php` runs before the core's `infosave` backup and `init` restore | verified (read `main.php:201`, `:1135`) | snapshot misses the history row: move the write to the previous request and accept `stale` detection |
| A2 | `backupAllNpcs` copies `plugin_extended_data` of every row, locked ones too | verified (read `:1488-1504`) | approach B storage behind `tesWorldSnapStore/Load`; per-NPC fix-up has nothing to read |
| A3 | `restoreNPC` skips locked rows; relationship restore does not | verified (read `:1525`, `:188-233`) | the fix-up becomes a no-op for locked rows, harmless |
| A4 | History rows written between saves carry the save's gamets and later plugin data | verified (read + SELECT: 262 rows for 244 NPCs at one gamets) | the `g <= T` filter is then redundant, harmless |
| A5 | `init` gamets is at or above the loaded save's `infosave` gamets | verified on the 8 latest loads in the live log; rows that would disprove it are deleted by the core's own prune | core and plugin both restore the previous save; 4.2 would report `stale` only by luck. Check in game (section 10) |
| A6 | Live counts in section 1 | verified by SELECT 2026-10-09 | re-run `--dry-run` before `--apply` |
| A7 | No foreign keys or triggers on `tes_*` tables | verified by SELECT | restore order would matter |
| A8 | Closed rows of `open` tables are final | verified (read the UPDATE statements in `ext/`) | move the table to `full` |
| A9 | The service NPC row is inert: no core job generates a profile, relationship or background life for it, and the NPC manager shows it without error | not verified (grep of `FROM core_npc_master` scans only; no run) | switch storage to a plugin table keyed by playthrough id inside `tesWorldSnapStore/Load`; nothing else changes |
| A10 | Unqualified core table names resolve to session temp tables under the core `sql` class (`SET search_path TO public`, `postgresql.class.php:36`) | not verified (PostgreSQL searches `pg_temp` first for relations; not run here) | tests of Parts 1, 4, 5 use throwaway `ZZZ_TestNPC_*` rows with cleanup, as `tools/test_ext.php` does |
| A11 | The talking model scores its own reaction with `#REL:` often enough for rule 4 | not verified (175 `relationship` history rows suggest yes) | set `TES_BOND_SKIP_SELF_SCORED = false` and accept stronger drops |
| A12 | 1 028 gamets per second holds (timescale constant) | measured once over 25 minutes | fear fades faster or slower; one constant |
| A13 | Profile regeneration does not need the memory lines that leave the bio | not verified | add the block to the bio text passed to the generator, or keep a 1-line summary in the bio |
| A14 | `renameNPC` does not carry `plugin_extended_data` to the new row | read (`plugin-npc-data.md:21`), not run | a renamed NPC loses mood and memory; add a copy step keyed on the rename event |
| A15 | A 16 to 60 KB namespace is fine for `setPluginData` and for the `infosave` request time | not verified | log the time; move growing tables to `open`/`tail` |
| A16 | The `tes_watch` key classes in 3.4 | not verified (by name) | a wrong key is either not rolled back or a rate limit resets |

---

## 9. What can go wrong in production

| Failure | Noticed by | Undone by |
|---|---|---|
| World restored from the wrong snapshot | Russian notice on every restore; `tools/state_admin.php --status` shows `horizon` | `tools/state_admin.php --undo-world` |
| Fix-up removes or replaces live NPC state wrongly | `horizon.npc_rows` > 0 on a load of the newest save; `error_log` line `[tes_state] npc fix` | `--undo-npc` |
| Snapshot not written at a save | notice `Снимок мира для сейва не записан`; later load reports `stale` and changes nothing | nothing lost; next save works |
| Service row deleted in the NPC manager | `none` notice on the next load | re-created at the next save |
| Core update drops the column from the backup or changes the restore order | Part 1 core contract check, run from `tools/after_update.sh` | `touch ext/tes_state/DISABLED`; state stays, rollback stops |
| Affinity falls too fast for many NPCs | `[REL]` log lines, NPC manager | `TES_BOND_NATIVE = false`; values come back from history on load or by the god's `.relation` |
| Two requests write one namespace at once | a lost grudge or memory line | accepted; one writer function per namespace keeps it rare |
| Anything else | | `touch CORE/ext/tes_state/DISABLED`, then `migrate_state_13.php --rollback` and revert Part 7 |

## 10. Open, needs the game

1. A5 for every kind of save (manual, quick, auto, after death).
2. Whether a playthrough auto-switch is always followed by `init`. If not, world tables of the other character stay until the first load.
3. A9 in the NPC manager UI.
4. Time of the `infosave` request with the snapshot.
5. Whether NPCs behave better with fear as the only extra line (item 16's goal is behaviour, tests cover the numbers).
